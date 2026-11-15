<script setup lang="ts">
import { inject } from 'vue';

import { t } from '@/lib/i18n';
import { Boxes } from '@/lib/icons';

import { gridKey } from './useItemsGrid';

/** Balance and cost of the line under the cursor, as Itqan shows them beside the invoice. */
const grid = inject(gridKey)!;
</script>

<template>
    <div v-if="grid.stock.value" class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg border border-line bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">
        <span class="flex items-center gap-1.5 font-semibold text-gray-900 dark:text-white"><Boxes class="size-4 text-brand-500" />{{ grid.stock.value.label }}</span>
        <span v-if="grid.stock.value.here !== null">
            {{ t('In this warehouse') }}: <b class="tabular-nums" dir="ltr">{{ grid.stock.value.here }}</b> {{ grid.stock.value.unit }}
        </span>
        <span>{{ t('In all warehouses') }}: <b class="tabular-nums" dir="ltr">{{ grid.stock.value.all }}</b> {{ grid.stock.value.unit }}</span>
        <span v-if="grid.stock.value.cost !== null">{{ t('Cost') }}: <b class="tabular-nums" dir="ltr">{{ grid.stock.value.cost }}</b></span>
    </div>
</template>
