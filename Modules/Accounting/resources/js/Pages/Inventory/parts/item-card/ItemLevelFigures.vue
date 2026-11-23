<script setup lang="ts">
import { computed } from 'vue';

import { t } from '@/lib/i18n';
import { isLowStockTone, toneText } from '@/lib/tones';

import type { StatusBadge } from '../types';
import type { ItemCardProduct, ItemLevels } from './types';

/**
 * The item's figures in one strip: stock limits (when `limits` is given), warehouse quantity,
 * reserved by open sales orders, coming in open purchase orders, and what is left available.
 * A quantity at or below a minimum limit takes that limit's color from the settings (`status`),
 * and so does the limit it reached. Shared by the item stock card and the item details window of the items grid.
 */
const props = defineProps<{ levels: ItemLevels; status?: StatusBadge | null; limits?: Pick<ItemCardProduct, 'max_limit' | 'min_limit_1' | 'min_limit_2'> | null }>();

const plain = 'text-gray-900 dark:text-white';
const reached = (tone: StatusBadge['tone']) => (props.status?.tone === tone ? toneText[tone] : plain);

const figures = computed(() => [
    ...(props.limits
        ? [
              { label: t('Max Limit'), value: props.limits.max_limit, tone: plain },
              { label: t('Min Limit 1'), value: props.limits.min_limit_1, tone: reached('stock-min-1') },
              { label: t('Min Limit 2'), value: props.limits.min_limit_2, tone: reached('stock-min-2') },
          ]
        : []),
    { label: t('Warehouse Qty'), value: props.levels.on_hand, tone: isLowStockTone(props.status?.tone) ? toneText[props.status.tone] : 'text-brand-600 dark:text-brand-400' },
    { label: t('Sales Orders Qty'), value: props.levels.reserved, tone: 'text-warning-600 dark:text-warning-400' },
    { label: t('Purchase Orders Qty'), value: props.levels.incoming, tone: 'text-blue-light-600 dark:text-blue-light-400' },
    { label: t('Available'), value: props.levels.available, tone: props.levels.available_negative ? 'text-error-600' : 'text-success-600 dark:text-success-400' },
]);
</script>

<template>
    <div class="grid grid-cols-2 gap-px bg-gray-200 sm:grid-cols-4 dark:bg-white/10" :class="limits ? 'lg:grid-cols-7' : ''">
        <div v-for="figure in figures" :key="figure.label" class="bg-card p-3 text-center">
            <div class="text-xs text-muted">{{ figure.label }}</div>
            <div class="mt-1 text-xl font-bold tabular-nums" :class="figure.tone" dir="ltr">{{ figure.value }}</div>
        </div>
    </div>
</template>
