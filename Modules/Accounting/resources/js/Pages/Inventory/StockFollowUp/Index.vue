<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { CircleCheck } from '@/lib/icons';

import { useReportFilters } from '../../Report/parts/useReportFilters';
import type { StatusBadge } from '../parts/types';
import FollowUpDetails from './FollowUpDetails.vue';
import SuggestionValue from './SuggestionValue.vue';
import type { Details, Suggestion } from './types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    alert_id: number;
    name: string;
    code: string | null;
    main_product: string | null;
    quantity: string;
    min_limit_1: string;
    min_limit_2: string;
    max_limit: string;
    level: StatusBadge | null;
    by_max: Suggestion;
    by_usage: Suggestion;
    since: string | null;
}

const props = defineProps<{
    category: string;
    categoryLabel: string;
    isAluminum: boolean;
    search: string;
    tabs: { value: string; label: string; href: string; count: number }[];
    counts: { badge: StatusBadge | null; count: number }[];
    groups: { color: string | null; rows: Row[] }[];
    details: Details | null;
    canEditLimits: boolean;
}>();

const url = new URL(window.location.href);
const { filters } = useReportFilters(
    {
        q: props.search,
        item: props.details?.id ?? null,
        from: url.searchParams.get('from'),
        to: url.searchParams.get('to'),
        day: url.searchParams.get('day'),
    } as Record<string, string | number | null>,
    { debounce: ['q'] },
);

const total = computed(() => props.groups.reduce((sum, group) => sum + group.rows.length, 0));
const columns = computed(() => (props.isAluminum ? 9 : 8));

/** An item's window starts on the last 30 days. */
function openItem(id: number): void {
    Object.assign(filters, { item: id, from: null, to: null, day: null });
}
</script>

<template>
    <PageHeader :title="t('Stock Follow-up')" :description="t('Items at Min Limit 1 or below, most critical first. An item leaves the list once its quantity rises above Min Limit 1.')" />

    <div class="grid gap-4">
        <Card>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <nav v-if="tabs.length > 1" class="inline-flex w-fit rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                    <Link
                        v-for="tab in tabs"
                        :key="tab.value"
                        :href="tab.href"
                        class="inline-flex items-center gap-2 rounded-md px-4 py-1.5 text-sm font-semibold transition"
                        :class="tab.value === category ? 'bg-card text-brand-600 shadow-theme-xs dark:text-brand-400' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'"
                    >
                        {{ tab.label }}
                        <span class="rounded-full bg-gray-200 px-2 text-xs text-gray-700 tabular-nums dark:bg-white/10 dark:text-gray-200">{{ tab.count }}</span>
                    </Link>
                </nav>
                <h2 v-else class="text-base font-semibold text-gray-900 dark:text-white">{{ categoryLabel }}</h2>
                <div class="sm:w-80">
                    <TextInput v-model="filters.q" type="search" :placeholder="t('Search by name, code or barcode')" />
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-3">
                <div v-for="(item, index) in counts" :key="index" class="flex items-center justify-between rounded-lg border border-line px-3 py-2">
                    <Badge v-if="item.badge" :tone="item.badge.tone" dot>{{ item.badge.label }}</Badge>
                    <span class="text-xl font-bold text-gray-900 tabular-nums dark:text-white">{{ item.count }}</span>
                </div>
            </div>
        </Card>

        <Card>
            <div class="-m-5 overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-gray-50/80 text-[13px] text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                        <tr class="border-b border-line">
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('Item') }}</th>
                            <th v-if="isAluminum" class="px-4 py-2.5 text-start font-medium">{{ t('Aluminum Type') }}</th>
                            <th class="px-4 py-2.5 text-end font-medium">{{ t('Quantity') }}</th>
                            <th class="px-4 py-2.5 text-end font-medium">{{ t('Min Limit 1') }}</th>
                            <th class="px-4 py-2.5 text-end font-medium">{{ t('Min Limit 2') }}</th>
                            <th class="px-4 py-2.5 text-end font-medium">{{ t('Max Limit') }}</th>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('Status') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium">{{ t('Order Suggestion (1 / 2)') }}</th>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('In Follow-up Since') }}</th>
                        </tr>
                    </thead>
                    <tbody v-for="(group, index) in groups" :key="index">
                        <tr v-if="isAluminum" class="bg-gray-50/70 dark:bg-white/[0.03]">
                            <td :colspan="columns" class="px-4 py-2 text-sm font-bold text-gray-800 dark:text-gray-100">
                                {{ t('Color') }}: {{ group.color ?? t('Unspecified color') }}
                                <span class="ms-2 text-xs font-normal text-muted">({{ group.rows.length }})</span>
                            </td>
                        </tr>
                        <tr v-for="row in group.rows" :key="row.alert_id" class="cursor-pointer border-b border-line transition hover:bg-brand-50/60 dark:hover:bg-brand-500/[0.06]" @click="openItem(row.id)">
                            <td class="px-4 py-2.5">
                                <div class="font-medium text-gray-900 dark:text-white">{{ row.name }}</div>
                                <div class="text-xs text-muted"><bdi dir="ltr">{{ row.code }}</bdi></div>
                            </td>
                            <td v-if="isAluminum" class="px-4 py-2.5 text-gray-600 dark:text-gray-300">{{ row.main_product ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-end font-semibold tabular-nums" dir="ltr">{{ row.quantity }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ row.min_limit_1 }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ row.min_limit_2 }}</td>
                            <td class="px-4 py-2.5 text-end text-muted tabular-nums" dir="ltr">{{ row.max_limit }}</td>
                            <td class="px-4 py-2.5"><Badge v-if="row.level" :tone="row.level.tone" dot>{{ row.level.label }}</Badge></td>
                            <td class="px-4 py-2.5 text-center whitespace-nowrap">
                                <SuggestionValue :suggestion="row.by_max" />
                                <span class="mx-1 text-gray-300">/</span>
                                <SuggestionValue :suggestion="row.by_usage" />
                            </td>
                            <td class="px-4 py-2.5 text-xs text-muted" dir="ltr">{{ row.since }}</td>
                        </tr>
                    </tbody>
                    <tbody v-if="!groups.length">
                        <tr>
                            <td :colspan="columns" class="px-6 py-16 text-center text-muted">
                                <CircleCheck class="mx-auto mb-3 size-12 text-success-500/70" />
                                {{ filters.q ? t('No items match the search.') : t('All items are above Min Limit 1. Nothing needs follow-up.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="total > 0" class="mt-5 -mb-1 text-xs text-muted">
                {{ t('Order suggestion 1 = Max Limit − current quantity; suggestion 2 = last 6 months withdrawals − current quantity. Click an item for its daily movements.') }}
            </p>
        </Card>
    </div>

    <FollowUpDetails v-if="details" :details="details" :category="category" :can-edit-limits="canEditLimits" :filters="filters" @close="Object.assign(filters, { item: null, from: null, to: null, day: null })" />
</template>
