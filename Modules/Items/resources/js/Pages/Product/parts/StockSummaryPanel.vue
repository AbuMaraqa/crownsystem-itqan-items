<script setup lang="ts">
/**
 * The item's stock, shown only: quantities change only through inventory documents. The same content
 * as the Livewire partial `product-stock-summary`, from ProductStockSummary on the server.
 */
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import { Info } from '@/lib/icons';
import { t } from '@/lib/i18n';

import type { StockSummary } from './types';

defineProps<{ stock: StockSummary | null; links: { label: string; href: string }[] }>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-start gap-3 rounded-xl border border-blue-light-200 bg-blue-light-50 p-4 text-sm text-blue-light-700 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-400">
            <Info class="mt-0.5 size-5 shrink-0" />
            <div class="space-y-1">
                <p class="font-semibold">{{ t('Quantities are not entered on the item.') }}</p>
                <p>{{ t('Stock changes only through inventory documents: opening balance, goods receipt, delivery note, transfer and stock count. Each document posts its journal entry, so the stock always matches the accounts.') }}</p>
                <p v-if="stock?.is_main">{{ t('This is a main item: its balance is the total of its sub-items, and stock is received and delivered on the sub-items.') }}</p>
            </div>
        </div>

        <div v-if="stock" class="overflow-hidden rounded-xl border border-line bg-card">
            <div class="grid grid-cols-2 gap-px bg-line sm:grid-cols-4">
                <div v-for="[label, value, tone] in [
                    [t('Warehouse Qty'), stock.levels.on_hand, 'text-brand-600'],
                    [t('Sales Orders Qty'), stock.levels.reserved, 'text-warning-600'],
                    [t('Purchase Orders Qty'), stock.levels.incoming, 'text-blue-light-600'],
                    [t('Available'), stock.levels.available, stock.levels.available_negative ? 'text-error-600' : 'text-success-600'],
                ]" :key="label" class="bg-card p-3 text-center">
                    <div class="text-xs text-muted">{{ label }}</div>
                    <div class="mt-1 text-xl font-bold tabular-nums" :class="tone" dir="ltr">{{ value }}</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-line px-4 py-3 text-sm">
                <Badge :tone="stock.status.tone" dot>{{ stock.status.label }}</Badge>
                <span v-if="stock.levels.weight" class="text-muted">{{ t('Weight') }}: <b class="text-ink tabular-nums">{{ stock.levels.weight }} {{ t('kg') }}</b></span>
                <span v-if="stock.value" class="text-muted">{{ t('Stock Value') }}: <b class="text-ink tabular-nums">{{ stock.value }}</b></span>
                <template v-if="stock.cost">
                    <template v-if="stock.cost.per_kg">
                        <span class="text-muted">{{ t('Cost per Kg') }}: <b class="text-ink tabular-nums">{{ stock.cost.per_kg }}</b></span>
                        <span class="text-muted">· {{ t('Cost per Piece') }}: <b class="text-ink tabular-nums">{{ stock.cost.average }}</b></span>
                    </template>
                    <span v-else class="text-muted">{{ t('Average Cost') }}: <b class="text-ink tabular-nums">{{ stock.cost.average }}</b></span>
                    <span class="text-muted">· {{ t('Stock Value') }}: <b class="text-ink tabular-nums">{{ stock.cost.value }}</b></span>
                </template>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-t border-line text-sm">
                    <thead class="bg-surface text-xs text-muted">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">{{ t('Warehouse') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ t('Primary location') }}</th>
                            <th class="px-4 py-2 text-end font-medium">{{ t('Quantity') }}</th>
                            <th class="px-4 py-2 text-end font-medium">{{ t('Weight') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in stock.warehouses" :key="index" class="border-t border-line">
                            <td class="px-4 py-2">{{ row.name ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono font-semibold" dir="ltr">{{ row.location ?? '—' }}</td>
                            <td class="px-4 py-2 text-end font-semibold tabular-nums">{{ row.quantity }}</td>
                            <td class="px-4 py-2 text-end text-muted tabular-nums">{{ row.weight ? `${row.weight} ${t('kg')}` : '—' }}</td>
                        </tr>
                        <tr v-if="!stock.warehouses.length">
                            <td colspan="4" class="px-4 py-6 text-center text-muted">{{ t('No stock yet') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="links.length" class="flex flex-wrap gap-2">
            <Button v-for="link in links" :key="link.href" size="sm" :href="link.href" external>{{ link.label }}</Button>
        </div>
    </div>
</template>
