<script setup lang="ts">
import { inject } from 'vue';

import Badge from '@/Components/ui/Badge.vue';
import { t } from '@/lib/i18n';
import { Boxes, Info } from '@/lib/icons';
import { toneText } from '@/lib/tones';

import { gridKey } from './useItemsGrid';

/** Balance and cost of the line under the cursor, as Itqan shows them beside the invoice; «Details» opens the item details window. */
const grid = inject(gridKey)!;
</script>

<template>
    <div v-if="grid.stock.value" class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg border border-line bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">
        <span class="flex items-center gap-1.5 font-semibold text-gray-900 dark:text-white"><Boxes class="size-4 text-brand-500" />{{ grid.stock.value.label }}</span>
        <span v-if="grid.stock.value.here !== null">
            {{ t('In this warehouse') }}: <b class="tabular-nums" dir="ltr">{{ grid.stock.value.here }}</b> {{ grid.stock.value.unit }}
        </span>
        <span>
            {{ t('In all warehouses') }}: <b class="tabular-nums" :class="grid.stock.value.tone ? toneText[grid.stock.value.tone] : ''" dir="ltr">{{ grid.stock.value.all }}</b> {{ grid.stock.value.unit }}
        </span>
        <Badge v-if="grid.stock.value.tone && grid.stock.value.status" :tone="grid.stock.value.tone" dot>{{ grid.stock.value.status }}</Badge>
        <span v-if="grid.stock.value.cost !== null">{{ t('Cost') }}: <b class="tabular-nums" dir="ltr">{{ grid.stock.value.cost }}</b></span>
        <button
            type="button"
            class="ms-auto inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10"
            @mousedown.prevent
            @click="grid.showDetails(grid.stock.value.product_id)"
        >
            <Info class="size-4" />{{ t('Details') }}
        </button>
    </div>
</template>
