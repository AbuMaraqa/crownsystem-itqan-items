<script setup lang="ts">
/**
 * The inventory tab (perpetual inventory): the item card sets the three limits only. Quantity and cost
 * come from inventory documents, summarised below for reading, with the balance of each colour.
 */
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import { t } from '@/lib/i18n';

import StockLimitFields from './StockLimitFields.vue';
import StockSummaryPanel from './StockSummaryPanel.vue';
import type { ErrorOf, ProductContext, ProductForm, ProductFormOptions } from './types';

const props = defineProps<{ form: InertiaForm<ProductForm>; options: ProductFormOptions; context: ProductContext; error: ErrorOf }>();

/** Saved variations with their balance; a new row has none yet. */
const variantRows = computed(() =>
    props.form.variations.filter((row) => row.id !== null && props.context.variantStock[String(row.id)]).map((row) => ({ row, stock: props.context.variantStock[String(row.id)] })),
);
</script>

<template>
    <div class="grid gap-6">
        <section>
            <h3 class="text-sm font-semibold">{{ t('Stock Limits') }}</h3>
            <p class="mb-4 text-sm text-muted">{{ t('In the stock unit (pieces for aluminum profiles). Max > Min 1 > Min 2; all three zero = the item is not monitored.') }}</p>
            <div class="grid gap-4 md:grid-cols-3">
                <StockLimitFields :row="form" :error="error" :editable="options.canEditLimits" />
            </div>
        </section>

        <StockSummaryPanel :stock="context.stock" :links="context.stockLinks" />

        <section v-if="variantRows.length">
            <h3 class="mb-2 text-sm font-semibold">{{ t('Stock by colour') }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-muted">
                            <th class="px-2 py-1.5 text-start font-medium">{{ t('Variation') }}</th>
                            <th class="px-2 py-1.5 text-end font-medium">{{ t('On Hand') }}</th>
                            <th class="px-2 py-1.5 text-end font-medium">{{ t('Available') }}</th>
                            <th class="px-2 py-1.5 text-end font-medium">{{ t('Weight (kg)') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="{ row, stock } in variantRows" :key="row.id ?? ''" class="border-t border-line">
                            <td class="px-2 py-1.5">{{ row.name }}</td>
                            <td class="px-2 py-1.5 text-end font-mono tabular-nums">{{ stock.on_hand }}</td>
                            <td class="px-2 py-1.5 text-end font-mono tabular-nums" :class="{ 'text-danger': stock.available_negative }">{{ stock.available }}</td>
                            <td class="px-2 py-1.5 text-end font-mono tabular-nums">{{ stock.weight ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
