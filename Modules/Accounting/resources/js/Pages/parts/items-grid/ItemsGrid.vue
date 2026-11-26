<script setup lang="ts">
import { computed, provide, shallowRef, toRef, watch } from 'vue';

import { t } from '@/lib/i18n';
import { ChevronDown, CircleAlert, Layers, Package, Plus, Trash2 } from '@/lib/icons';

import BarcodeEntry from './BarcodeEntry.vue';
import { computedCell, iconButton, numberInput, cellInput } from './classes';
import GridSearchDropdown from './GridSearchDropdown.vue';
import ItemDetailsModal from './ItemDetailsModal.vue';
import PiecesPanel from './PiecesPanel.vue';
import StockPanel from './StockPanel.vue';
import type { DocumentTotals, GridConfig, GridContext, GridRow } from './types';
import { gridKey, money, quantityLabel, useItemsGrid } from './useItemsGrid';

/**
 * The one items grid of every trade document (quotations, orders, invoices, returns…): a main
 * weight item with its sub-items always open under it, quantity items as plain rows, optional
 * group rows (a category and its items), barcode entry, suggested accessories, the focused line's
 * stock and cost. Keyboard first: Enter moves to the next field and adds a row at the end, the
 * arrows move between rows, Ctrl+Delete removes a row.
 *
 *   <ItemsGrid v-model:items="form.items" v-model:totals="totals" :config="{ document: 'sales-quotation', editable, showDiscount: true }"
 *              :context="{ partyId: form.client_id, exchangeRate: form.exchange_rate, warehouseId: form.warehouse_id, documentDiscount: form.quotation_discount, taxRate }" />
 *
 * Saving sends `items` as they are; the document's service normalizes and recomputes them.
 */
const props = defineProps<{ config: GridConfig; context: GridContext; error?: string }>();

const items = defineModel<GridRow[]>('items', { required: true });
const totals = defineModel<DocumentTotals>('totals');

const root = shallowRef<HTMLElement | null>(null);
const grid = useItemsGrid(toRef(items), () => props.config, () => props.context, root);
provide(gridKey, grid);

watch(grid.totals, (value) => (totals.value = value), { immediate: true });

const c = grid.config;
const searchTabs = computed(() => (c.value.groups ? [{ mode: 'item', label: t('Item') }, { mode: 'category', label: t('Category') }] : []));
const columnCount = computed(() => 5 + (c.value.showLength ? 1 : 0) + (c.value.showPrices ? 2 : 0) + (c.value.showDiscount ? 2 : 0));

const priceFields = ['discount_rate', 'discount_amount'] as const;
</script>

<template>
    <div ref="root" class="space-y-3" @focusin="grid.noteStockFocus">
        <div v-if="c.barcode || grid.reprice.value" class="flex flex-wrap items-start gap-3">
            <BarcodeEntry v-if="c.barcode" />

            <!-- A new party does not change typed prices until the user agrees. -->
            <div
                v-if="grid.reprice.value"
                class="flex flex-1 flex-wrap items-center gap-3 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:border-warning-500/25 dark:bg-warning-500/10 dark:text-warning-300"
            >
                <CircleAlert class="size-4 shrink-0" />
                <span class="font-medium">{{ t('The prices differ for this party. Do you want to change the prices?') }}</span>
                <span class="ms-auto flex gap-2">
                    <button type="button" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600" @click="grid.applyReprice()">{{ t('Change prices') }}</button>
                    <button type="button" class="rounded-lg border border-gray-200 bg-card px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/5" @click="grid.reprice.value = null">{{ t('Keep prices') }}</button>
                </span>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-line" :class="error ? 'border-error-300 dark:border-error-500/50' : ''">
            <table class="w-full min-w-[56rem] border-collapse text-sm">
                <thead>
                    <tr class="bg-gray-50 text-xs font-medium text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                        <th class="w-12 px-2 py-2.5 text-center font-medium">#</th>
                        <th class="min-w-64 px-2 py-2.5 text-start font-medium">{{ t('Item') }}</th>
                        <th class="w-36 px-2 py-2.5 text-end font-medium">{{ t('Quantity') }}</th>
                        <th v-if="c.showLength" class="w-28 px-2 py-2.5 text-end font-medium">{{ t('Length') }}</th>
                        <th class="w-36 px-2 py-2.5 text-end font-medium">{{ t('Actual Weight') }}</th>
                        <template v-if="c.showPrices">
                            <th class="w-36 px-2 py-2.5 text-end font-medium">{{ t('Price') }}</th>
                            <template v-if="c.showDiscount">
                                <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('Discount') }} %</th>
                                <th class="w-32 px-2 py-2.5 text-end font-medium">{{ t('Discount Amount') }}</th>
                            </template>
                            <th class="w-36 px-2 py-2.5 text-end font-medium">{{ t('Total') }}</th>
                        </template>
                        <th class="w-20 px-2 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                    </tr>
                </thead>

                <tbody v-for="(row, rowIndex) in items" :key="row._key" class="border-t border-line">
                    <!-- A group: a coloured header with the chosen category's items under it. -->
                    <tr v-if="row.row_type === 'category'" class="bg-brand-50/70 dark:bg-brand-500/[0.07]">
                        <td class="px-2 py-2 text-center"><Layers class="mx-auto size-4 text-brand-500" /></td>
                        <td class="px-2 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-brand-700 dark:text-brand-300">{{ row.category_label }}</span>
                                <span class="rounded-full bg-brand-100 px-2 py-0.5 text-[11px] font-medium text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">{{ grid.groupCount(rowIndex) }} {{ t('Items') }}</span>
                                <button
                                    v-if="c.editable"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-card px-2 py-1 text-xs font-medium text-brand-600 hover:bg-brand-50 dark:border-brand-500/30 dark:text-brand-400 dark:hover:bg-brand-500/10"
                                    @click="grid.addItemToGroup(rowIndex, true)"
                                >
                                    <Plus class="size-3.5" />{{ t('Add Item to Group') }}
                                </button>
                            </div>
                        </td>
                        <!-- A group has no price: these add up its items. -->
                        <td class="px-2 py-2 text-end font-semibold tabular-nums text-brand-700 dark:text-brand-300" :title="t('Total Weight')" dir="ltr">{{ money(grid.groupTotals(rowIndex).totalWeight) }}</td>
                        <td v-if="c.showLength" class="px-2 py-2 text-center text-gray-300 dark:text-gray-600">–</td>
                        <td class="px-2 py-2 text-end font-semibold tabular-nums text-brand-700 dark:text-brand-300" :title="t('Actual Weight')" dir="ltr">{{ money(grid.groupTotals(rowIndex).actualWeight) }}</td>
                        <template v-if="c.showPrices">
                            <td class="px-2 py-2 text-end font-semibold tabular-nums text-brand-700 dark:text-brand-300" :title="t('Price per Kg')" dir="ltr">{{ money(grid.groupTotals(rowIndex).pricePerKg) }}</td>
                            <template v-if="c.showDiscount"><td /><td /></template>
                            <td class="px-2 py-2 text-end font-bold tabular-nums text-brand-700 dark:text-brand-300" dir="ltr">{{ money(grid.groupTotals(rowIndex).lineTotal) }}</td>
                        </template>
                        <td class="px-2 py-2 text-center">
                            <button v-if="c.editable" type="button" :class="[iconButton, 'hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10']" :aria-label="t('Remove')" :title="t('Remove')" @click="grid.removeRow(rowIndex)">
                                <Trash2 />
                            </button>
                        </td>
                    </tr>

                    <template v-else>
                        <tr :class="row.category_id ? 'bg-brand-25/60 dark:bg-brand-500/[0.03]' : 'hover:bg-gray-50/60 dark:hover:bg-white/[0.02]'">
                            <td class="px-2 py-1.5 text-center text-xs text-muted tabular-nums" :class="row.category_id ? 'border-s-2 border-brand-300 dark:border-brand-500/40' : ''">{{ grid.itemNumber(rowIndex) }}</td>

                            <!-- Item: search by name, code or barcode -->
                            <td class="px-2 py-1.5" :class="row.category_id ? 'ps-6' : ''">
                                <div class="flex items-center gap-2">
                                    <Package v-if="row.pricing_type === 'weight'" class="size-4 shrink-0 text-brand-500" :title="t('Main Item')" />
                                    <input
                                        v-model="row.product_label"
                                        type="text"
                                        autocomplete="off"
                                        :data-cell="`${rowIndex}:product`"
                                        :readonly="!c.editable"
                                        :placeholder="t('Search by name, sku or barcode.')"
                                        :class="[cellInput, row.pricing_type === 'weight' ? 'font-semibold' : '']"
                                        @input="grid.queueSearch(rowIndex, ($event.target as HTMLInputElement).value)"
                                        @focus="grid.onProductFocus(rowIndex)"
                                        @blur="grid.onProductBlur(rowIndex)"
                                        @keydown="grid.onProductKey($event, rowIndex)"
                                    />
                                </div>
                                <GridSearchDropdown
                                    :open="grid.search.open && grid.search.rowIndex === rowIndex"
                                    :anchor="grid.search.anchor"
                                    :results="grid.search.results"
                                    :highlight="grid.search.highlight"
                                    :loading="grid.search.loading"
                                    :tabs="searchTabs"
                                    :mode="grid.search.mode"
                                    @choose="grid.chooseResult(rowIndex, $event)"
                                    @hover="grid.search.highlight = $event"
                                    @mode="grid.setSearchMode(rowIndex, $event as 'item' | 'category')"
                                />

                            </td>

                            <!-- Quantity, or the total weight of the sub-items for a weight item -->
                            <td class="px-2 py-1.5 align-top">
                                <template v-if="row.pricing_type !== 'weight'">
                                    <input
                                        v-model="row.quantity"
                                        type="text"
                                        inputmode="decimal"
                                        :data-cell="`${rowIndex}:quantity`"
                                        :readonly="!c.quantitiesEditable"
                                        :class="numberInput"
                                        @focus="($event.target as HTMLInputElement).select()"
                                        @input="grid.clampQuantity(row)"
                                        @keydown="grid.onCellKey($event, rowIndex, 'quantity')"
                                    />
                                    <span v-if="c.quantityLimitLabel && row.max_quantity !== undefined && row.max_quantity !== null" class="mt-0.5 block text-end text-[10px] text-muted">
                                        {{ c.quantityLimitLabel }}: {{ quantityLabel(row.max_quantity) }}
                                    </span>
                                </template>
                                <template v-else>
                                    <div :class="computedCell" :title="t('Total Weight')" dir="ltr">{{ money(row.total_weight) }}</div>
                                    <span class="mt-0.5 block text-end text-[10px] text-muted">{{ t('Total weight of sub-items') }}</span>
                                </template>
                            </td>

                            <td v-if="c.showLength" class="px-2 py-1.5 align-top">
                                <input
                                    v-model="row.length"
                                    type="text"
                                    inputmode="decimal"
                                    :data-cell="`${rowIndex}:length`"
                                    :readonly="!c.editable"
                                    :class="numberInput"
                                    @focus="($event.target as HTMLInputElement).select()"
                                    @keydown="grid.onCellKey($event, rowIndex, 'length')"
                                />
                            </td>

                            <!-- Actual (scale) weight: weight items only -->
                            <td class="px-2 py-1.5 align-top">
                                <template v-if="row.pricing_type === 'weight'">
                                    <input
                                        v-model="row.actual_weight"
                                        type="text"
                                        inputmode="decimal"
                                        :data-cell="`${rowIndex}:actual_weight`"
                                        :readonly="!c.actualWeightEditable"
                                        :class="[numberInput, grid.rowNotices(row).length ? 'border-warning-300 bg-warning-25 dark:border-warning-500/40 dark:bg-warning-500/10' : '']"
                                        @focus="($event.target as HTMLInputElement).select()"
                                        @keydown="grid.onCellKey($event, rowIndex, 'actual_weight')"
                                    />
                                    <!-- Notices only: they never block saving. -->
                                    <span v-for="notice in grid.rowNotices(row)" :key="notice.type" class="mt-0.5 block text-end text-[10px] font-medium text-warning-700 dark:text-warning-400" :title="notice.hint">{{ notice.message }}</span>
                                </template>
                                <span v-else class="flex h-9 items-center justify-center text-gray-300 dark:text-gray-600">—</span>
                            </td>

                            <template v-if="c.showPrices">
                                <td class="px-2 py-1.5 align-top">
                                    <input
                                        v-model="row.unit_price"
                                        type="text"
                                        inputmode="decimal"
                                        :data-cell="`${rowIndex}:unit_price`"
                                        :readonly="!c.pricesEditable"
                                        :class="numberInput"
                                        @focus="($event.target as HTMLInputElement).select()"
                                        @keydown="grid.onCellKey($event, rowIndex, 'unit_price')"
                                    />
                                    <span v-if="row.pricing_type === 'weight'" class="mt-0.5 block text-end text-[10px] text-muted">{{ t('Price per Kg') }}</span>
                                </td>

                                <!-- Line discount: the rate or the amount; the other is derived. -->
                                <template v-if="c.showDiscount">
                                    <td v-for="name in priceFields" :key="name" class="px-2 py-1.5 align-top">
                                        <input
                                            v-model="row[name]"
                                            type="text"
                                            inputmode="decimal"
                                            :data-cell="`${rowIndex}:${name}`"
                                            :readonly="!c.pricesEditable"
                                            :class="numberInput"
                                            @focus="($event.target as HTMLInputElement).select()"
                                            @input="grid.setDiscountMode(row, name === 'discount_amount' ? 'amount' : 'rate')"
                                            @blur="grid.normalizeDiscount(row)"
                                            @keydown="grid.onCellKey($event, rowIndex, name)"
                                        />
                                    </td>
                                </template>

                                <td class="px-2 py-1.5 align-top">
                                    <div :class="[computedCell, 'font-semibold text-gray-900 dark:text-white']" dir="ltr">{{ money(row.line_total) }}</div>
                                </td>
                            </template>

                            <td class="px-2 py-1.5 align-top">
                                <div class="flex items-center justify-center gap-0.5">
                                    <button
                                        v-if="row.pricing_type === 'weight'"
                                        type="button"
                                        :class="iconButton"
                                        :aria-label="t('Sub Items')"
                                        :title="t('Sub Items')"
                                        :aria-expanded="!row.collapsed"
                                        @click="row.collapsed = !row.collapsed"
                                    >
                                        <ChevronDown class="transition-transform" :class="row.collapsed ? '' : 'rotate-180'" />
                                    </button>
                                    <button
                                        v-if="c.editable"
                                        type="button"
                                        :class="[iconButton, 'hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10']"
                                        :aria-label="t('Remove')"
                                        :title="t('Remove')"
                                        @click="grid.removeRow(rowIndex)"
                                    >
                                        <Trash2 />
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="row.pricing_type === 'weight' && !row.collapsed">
                            <td :colspan="columnCount" class="p-0"><PiecesPanel :row-index="rowIndex" /></td>
                        </tr>

                        <!-- Suggested accessories: a suggestion, not a rule. -->
                        <tr v-if="c.editable && grid.pendingSuggestions(row).length">
                            <td :colspan="columnCount" class="border-s-2 border-warning-300 bg-warning-25 px-3 py-2 dark:border-warning-500/40 dark:bg-warning-500/[0.05]">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold text-warning-700 dark:text-warning-400">{{ t('Suggested accessories:') }}</span>
                                    <button
                                        v-for="suggestion in grid.pendingSuggestions(row)"
                                        :key="suggestion.product.id"
                                        type="button"
                                        :title="t('Add to the order')"
                                        class="inline-flex max-w-xs items-center gap-1 rounded-full border border-warning-200 bg-card px-2.5 py-1 text-xs text-gray-700 transition-colors hover:border-brand-300 hover:text-brand-700 dark:border-warning-500/30 dark:text-gray-200 dark:hover:text-brand-300"
                                        @click="grid.addAccessory(rowIndex, suggestion.product.id)"
                                    >
                                        <Plus class="size-3.5 shrink-0" />
                                        <span class="truncate">{{ suggestion.product.label }}</span>
                                        <span class="shrink-0 tabular-nums text-muted" dir="ltr">× {{ quantityLabel(grid.accessoryQuantity(row, suggestion)) }}</span>
                                    </button>
                                    <button
                                        v-if="grid.pendingSuggestions(row).length > 1"
                                        type="button"
                                        class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10"
                                        @click="grid.addAllAccessories(rowIndex)"
                                    >
                                        {{ t('Add All') }}
                                    </button>
                                    <button type="button" class="ms-auto rounded-lg px-2 py-1 text-xs text-muted hover:bg-gray-100 dark:hover:bg-white/5" @click="grid.dismissSuggestions(row)">
                                        {{ t('Hide') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>

                <tbody v-if="items.length === 0">
                    <tr class="border-t border-line">
                        <td :colspan="columnCount" class="px-3 py-8 text-center text-sm text-muted">{{ t('No items added yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

        <StockPanel />
        <ItemDetailsModal :warehouse-id="context.warehouseId" />

        <div class="flex flex-wrap items-center justify-between gap-2">
            <button
                v-if="c.editable"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 px-3 py-1.5 text-sm font-medium text-brand-600 transition-colors hover:bg-brand-50 dark:border-brand-500/30 dark:text-brand-400 dark:hover:bg-brand-500/10"
                @click="grid.addRow(true)"
            >
                <Plus />{{ t('Add Item') }}
            </button>
            <p v-if="c.editable" class="text-xs text-muted">
                {{ t('Enter: next field / new row') }} · {{ t('Arrow keys: move between rows') }} · {{ t('Ctrl+Delete: remove row') }}
            </p>
        </div>
    </div>
</template>
