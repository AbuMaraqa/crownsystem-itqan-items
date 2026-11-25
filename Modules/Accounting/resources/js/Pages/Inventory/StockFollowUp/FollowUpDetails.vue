<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { can, t } from '@/lib/i18n';
import { ExternalLink } from '@/lib/icons';
import { route } from '@/lib/route';

import SuggestionValue from './SuggestionValue.vue';
import type { Details } from './types';

/**
 * The window of one item in the follow-up section: the summary, the balance day by day (a day
 * with movements opens them), the two order suggestions, and the stock limits with their log.
 * The period, the day and the item live in the page URL (`filters`).
 */
const props = defineProps<{
    details: Details;
    category: string;
    canEditLimits: boolean;
    filters: Record<string, string | number | null>;
}>();

const emit = defineEmits<{ close: [] }>();

const open = computed({
    get: () => true,
    set: (value: boolean) => {
        if (!value) emit('close');
    },
});

const limits = useForm({ ...props.details.limits });

watch(
    () => props.details.limits,
    (next) => {
        limits.defaults({ ...next });
        limits.reset();
    },
);

function saveLimits(): void {
    limits.put(route(`accounting.inventory.follow-up.${props.category}.limits`, props.details.id), { preserveScroll: true, preserveState: true });
}

function selectDay(date: string, movements: number): void {
    if (movements > 0) props.filters.day = props.filters.day === date ? null : date;
}

function setRange(key: 'from' | 'to', value: string | number | null | undefined): void {
    props.filters[key] = value ? String(value) : null;
    props.filters.day = null;
}

function lastThirtyDays(): void {
    Object.assign(props.filters, { from: null, to: null, day: null });
}

const fields = [
    ['max_limit', 'Max Limit'],
    ['min_limit_1', 'Min Limit 1'],
    ['min_limit_2', 'Min Limit 2'],
] as const;
</script>

<template>
    <Modal v-model:open="open" :title="`${t('Item Details')}: ${details.label}`" width="max-w-6xl">
        <div class="grid gap-5">
            <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
                <span>
                    <bdi dir="ltr">{{ details.code }}</bdi>
                    <template v-if="details.price_group"> · {{ t('Price Group') }}: {{ details.price_group }}</template>
                    <template v-if="details.since"> · {{ t('In follow-up since') }} <span dir="ltr">{{ details.since }}</span></template>
                </span>
                <Button v-if="can('Stock Item Card View')" size="sm" :href="route('accounting.inventory.item-card', details.id)"><ExternalLink />{{ t('Item Stock Card') }}</Button>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div v-for="[caption, value] in [[t('Current Quantity'), details.quantity], [t('Max Limit'), details.max_limit], [t('Min Limit 1'), details.min_limit_1], [t('Min Limit 2'), details.min_limit_2]]" :key="caption" class="rounded-lg border border-line px-3 py-2.5 text-center">
                    <div class="text-xs text-muted">{{ caption }}</div>
                    <div class="mt-1 text-xl font-bold text-gray-900 tabular-nums dark:text-white" dir="ltr">{{ value }}</div>
                </div>
                <div class="col-span-2 rounded-lg border border-line px-3 py-2.5 text-center md:col-span-1">
                    <div class="text-xs text-muted">{{ t('Status') }}</div>
                    <div class="mt-1.5"><Badge :tone="details.status.tone" dot>{{ details.status.label }}</Badge></div>
                </div>
            </div>

            <div class="grid gap-5 xl:grid-cols-3">
                <div class="xl:col-span-2">
                    <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ t('Daily Balance') }}</h3>
                        <div class="flex items-end gap-2">
                            <TextInput :model-value="details.from" type="date" :label="t('From')" @update:model-value="(value) => setRange('from', value)" />
                            <TextInput :model-value="details.to" type="date" :label="t('To')" @update:model-value="(value) => setRange('to', value)" />
                            <Button @click="lastThirtyDays">{{ t('Last 30 days') }}</Button>
                        </div>
                    </div>

                    <div class="max-h-[28rem] overflow-auto rounded-lg border border-line">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-gray-50 text-xs text-muted dark:bg-gray-900">
                                <tr>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('Date') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('Start of Day Balance') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('Total Incoming') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('Total Outgoing') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('End of Day Balance') }}</th>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody v-for="row in details.ledger.days" :key="row.date" class="border-t border-line">
                                <tr
                                    :class="[
                                        row.movements > 0 ? 'cursor-pointer hover:bg-brand-50/60 dark:hover:bg-brand-500/[0.06]' : 'text-gray-400',
                                        details.day === row.date ? 'bg-brand-50 dark:bg-brand-500/10' : '',
                                    ]"
                                    @click="selectDay(row.date, row.movements)"
                                >
                                    <td class="px-3 py-1.5 whitespace-nowrap" dir="ltr">
                                        {{ row.label }}
                                        <span v-if="row.movements > 0" class="ms-1 rounded bg-brand-50 px-1.5 text-[10px] font-semibold text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">{{ row.movements }}</span>
                                    </td>
                                    <td class="px-3 py-1.5 text-end tabular-nums" dir="ltr">{{ row.opening }}</td>
                                    <td class="px-3 py-1.5 text-end tabular-nums" :class="row.incoming ? 'font-medium text-success-700 dark:text-success-400' : ''" dir="ltr">{{ row.incoming ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-end tabular-nums" :class="row.outgoing ? 'font-medium text-error-700 dark:text-error-400' : ''" dir="ltr">{{ row.outgoing ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-end font-semibold tabular-nums" dir="ltr">{{ row.closing }}</td>
                                    <td class="px-3 py-1.5">
                                        <Badge v-if="row.status" :tone="row.status.tone">{{ row.status.label }}</Badge>
                                        <span v-else class="text-xs">—</span>
                                    </td>
                                </tr>
                                <tr v-if="details.day === row.date && details.movements">
                                    <td colspan="6" class="border-s-2 border-brand-300 bg-brand-25 px-3 py-2 dark:border-brand-500/40 dark:bg-brand-500/[0.04]">
                                        <div class="mb-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200">{{ t('Movements of the day') }}</div>
                                        <table class="w-full text-xs">
                                            <thead class="text-muted">
                                                <tr>
                                                    <th class="px-2 py-1 text-start font-medium">{{ t('Time') }}</th>
                                                    <th class="px-2 py-1 text-start font-medium">{{ t('Type') }}</th>
                                                    <th class="px-2 py-1 text-end font-medium">{{ t('Quantity') }}</th>
                                                    <th class="px-2 py-1 text-end font-medium">{{ t('Balance After') }}</th>
                                                    <th class="px-2 py-1 text-start font-medium">{{ t('User') }}</th>
                                                    <th class="px-2 py-1 text-start font-medium">{{ t('Document / Note') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="movement in details.movements" :key="movement.id" class="border-t border-line">
                                                    <td class="px-2 py-1.5 whitespace-nowrap" dir="ltr">{{ movement.time }}</td>
                                                    <td class="px-2 py-1.5"><Badge v-if="movement.type" :tone="movement.type.tone">{{ movement.type.label }}</Badge></td>
                                                    <td class="px-2 py-1.5 text-end font-semibold tabular-nums" :class="movement.incoming ? 'text-success-700 dark:text-success-400' : 'text-error-700 dark:text-error-400'" dir="ltr">{{ movement.quantity }}</td>
                                                    <td class="px-2 py-1.5 text-end tabular-nums" dir="ltr">{{ movement.balance }}</td>
                                                    <td class="px-2 py-1.5">
                                                        <UserCell v-if="movement.user" :name="movement.user.name" :avatar="movement.user.avatar" :detail="movement.user.detail" />
                                                        <span v-else>—</span>
                                                    </td>
                                                    <td class="px-2 py-1.5">
                                                        <a v-if="movement.url && movement.reference_no" :href="movement.url" target="_blank" class="font-medium text-brand-600 hover:underline dark:text-brand-400"><bdi dir="ltr">{{ movement.reference_no }}</bdi></a>
                                                        <bdi v-else-if="movement.reference_no" dir="ltr">{{ movement.reference_no }}</bdi>
                                                        <div v-if="movement.description" class="text-muted">{{ movement.description }}</div>
                                                        <div class="text-gray-400">{{ movement.warehouse }}</div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        <span>{{ t('Period check') }}:</span>
                        <span dir="ltr">{{ details.ledger.opening }} + {{ details.ledger.incoming }} − {{ details.ledger.outgoing }} = <strong>{{ details.ledger.closing }}</strong></span>
                        <span class="text-gray-400">({{ t('start balance + incoming − outgoing = end balance') }})</span>
                        <span class="text-gray-400">· {{ t('Transfers between warehouses are not shown; they do not change the total quantity.') }}</span>
                    </div>
                </div>

                <div class="grid content-start gap-5">
                    <div class="rounded-lg border border-line p-4">
                        <h3 class="mb-3 text-sm font-bold text-gray-900 dark:text-white">{{ t('Order Suggestion') }}</h3>
                        <p v-if="!details.suggestions" class="text-sm text-muted">{{ t('The item is not monitored, so no order is suggested.') }}</p>
                        <div v-else class="grid gap-3 text-sm">
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-gray-700 dark:text-gray-200">1. {{ t('By Max Limit') }}</span>
                                    <SuggestionValue :suggestion="details.suggestions.by_max" />
                                </div>
                                <div class="mt-1 text-xs text-muted" dir="ltr">{{ details.suggestions.by_max.max }} − {{ details.suggestions.by_max.current }} = {{ details.suggestions.by_max.value }}</div>
                                <div class="mt-0.5 text-xs text-gray-400">{{ t('Max Limit − current quantity: brings the item back to about 6 months of stock.') }}</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-gray-700 dark:text-gray-200">2. {{ t('By last 6 months withdrawals') }}</span>
                                    <SuggestionValue :suggestion="details.suggestions.by_usage" />
                                </div>
                                <template v-if="details.suggestions.by_usage.has_data">
                                    <template v-if="details.suggestions.by_usage.months">
                                        <div class="mt-1 text-xs text-muted">{{ t('Less than 6 months of movements: monthly average since the first movement × 6') }}</div>
                                        <div class="text-xs text-muted" dir="ltr">{{ details.suggestions.by_usage.withdrawn }} ÷ {{ details.suggestions.by_usage.months }} × 6 = {{ details.suggestions.by_usage.projected }}</div>
                                    </template>
                                    <div class="mt-1 text-xs text-muted" dir="ltr">{{ details.suggestions.by_usage.projected }} − {{ details.suggestions.by_usage.current }} = {{ details.suggestions.by_usage.value }}</div>
                                </template>
                                <div class="mt-0.5 text-xs text-gray-400">{{ t('Withdrawn = sales issues minus returns, without stock count adjustments.') }}</div>
                            </div>
                            <p class="text-xs text-gray-400">{{ t('Suggestions only; the system never creates an order automatically.') }}</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-line p-4">
                        <h3 class="mb-3 text-sm font-bold text-gray-900 dark:text-white">{{ t('Stock Limits') }}</h3>
                        <form v-if="canEditLimits" class="grid gap-2" @submit.prevent="saveLimits">
                            <TextInput
                                v-for="[field, caption] in fields"
                                :key="field"
                                v-model="limits[field]"
                                type="number"
                                step="any"
                                min="0"
                                dir="ltr"
                                :label="t(caption)"
                                :error="limits.errors[field]"
                            />
                            <p class="text-xs text-gray-400">{{ t('Max > Min 1 > Min 2. Set all three to zero to stop monitoring the item.') }}</p>
                            <Button type="submit" variant="primary" :loading="limits.processing">{{ t('Save Limits') }}</Button>
                        </form>
                        <p v-else class="text-xs text-muted">{{ t('You do not have permission to change the stock limits.') }}</p>

                        <div v-if="details.history.length" class="mt-4 border-t border-line pt-3">
                            <div class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">{{ t('Limits Change Log') }}</div>
                            <ul class="grid gap-2 text-xs">
                                <li v-for="change in details.history" :key="change.id" class="rounded-lg bg-gray-50 p-2 dark:bg-white/5">
                                    <div class="flex items-center justify-between gap-2">
                                        <UserCell v-if="change.user" :name="change.user.name" :avatar="change.user.avatar" :detail="change.user.detail" />
                                        <span v-else>{{ t('System') }}</span>
                                        <span class="text-gray-400" dir="ltr">{{ change.at }}</span>
                                    </div>
                                    <div class="mt-1 text-muted" dir="ltr">{{ change.old }} → <strong class="text-gray-800 dark:text-gray-100">{{ change.new }}</strong></div>
                                </li>
                            </ul>
                            <div class="mt-1 text-[10px] text-gray-400">{{ t('Max / Min 1 / Min 2') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
