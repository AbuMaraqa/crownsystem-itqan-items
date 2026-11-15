<script setup lang="ts">
import { ref, shallowRef, watch } from 'vue';

import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { getJson, type QuantityRow } from './types';

/**
 * The item's quantity and limits, and its pieces when it is a main item. The main item's piece count
 * is the sum of its pieces; its weight is its own kg. Under Min Limit 1 is shown in red.
 */
const props = defineProps<{ productId: number | null }>();

const open = defineModel<boolean>('open', { required: true });

const rows = shallowRef<QuantityRow[]>([]);
const loading = ref(false);

watch(
    () => [props.productId, open.value] as const,
    async ([id, isOpen]) => {
        if (!id || !isOpen) return;

        loading.value = true;

        try {
            rows.value = await getJson<QuantityRow[]>(route('products.tree.quantity', id));
        } finally {
            loading.value = false;
        }
    },
);

const number = (value: number, digits: number): string => String(Number(value.toFixed(digits)));
const limit = (value: number | null): string => (value === null ? '—' : String(value));
</script>

<template>
    <Modal v-model:open="open" :title="t('Item Quantity')" width="max-w-4xl">
        <div class="overflow-x-auto" :class="{ 'opacity-60': loading }">
            <table class="w-full min-w-[44rem] text-sm">
                <thead>
                    <tr class="bg-surface text-muted">
                        <th class="px-3 py-2 text-start font-medium">{{ t('Item Number') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ t('Item Name') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('Quantity') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('Weight (kg)') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ t('Unit') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('Min Limit 1') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('Min Limit 2') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('Max Limit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-line"
                        :class="row.is_sub ? 'text-ink even:bg-brand-50/60 dark:even:bg-brand-500/5' : 'bg-gray-900 font-semibold text-white dark:bg-black'"
                    >
                        <td class="px-3 py-1.5 font-mono"><bdi dir="ltr">{{ row.sku }}</bdi></td>
                        <td class="px-3 py-1.5">
                            {{ row.name }}
                            <span v-if="row.is_total" class="ms-1 text-xs font-normal text-gray-300">({{ t('Total of sub-items') }})</span>
                        </td>
                        <td class="px-3 py-1.5 text-end font-mono tabular-nums" :class="{ 'font-semibold text-error-600 dark:text-error-400': row.is_low && row.is_sub, 'text-error-300': row.is_low && !row.is_sub }">
                            {{ number(row.quantity, 2) }}
                        </td>
                        <td class="px-3 py-1.5 text-end font-mono tabular-nums">{{ number(row.weight, 3) }}</td>
                        <td class="px-3 py-1.5">{{ row.unit || '—' }}</td>
                        <td class="px-3 py-1.5 text-end font-mono tabular-nums">{{ limit(row.min_limit_1) }}</td>
                        <td class="px-3 py-1.5 text-end font-mono tabular-nums">{{ limit(row.min_limit_2) }}</td>
                        <td class="px-3 py-1.5 text-end font-mono tabular-nums">{{ limit(row.max_limit) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Modal>
</template>
