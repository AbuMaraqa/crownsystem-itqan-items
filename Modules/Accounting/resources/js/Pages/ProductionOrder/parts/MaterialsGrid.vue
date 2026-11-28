<script setup lang="ts">
import { nextTick, onBeforeUnmount, reactive, ref } from 'vue';

import { t } from '@/lib/i18n';
import { Plus, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import { getJson, withQuery } from '../../parts/http';
import GridSearchDropdown from '../../parts/items-grid/GridSearchDropdown.vue';
import type { Anchor } from '../../parts/items-grid/useItemsGrid';
import type { SearchResult } from '../../parts/items-grid/types';
import { blankMaterial, type MaterialLine } from '../types';

/**
 * The items a production order consumes, entered like the lines of sales and purchase orders: type in the
 * item cell, pick with the arrows and Enter, Enter moves to the next cell and opens a new row at the end,
 * Ctrl+Delete removes the row. A weight item costs its weight × the cost per kg, any other its quantity ×
 * the unit cost (ProductionCost on the server); picking an item fills its description, pricing type and cost.
 */
const props = defineProps<{ totals: number[]; editable: boolean; errors: Record<string, string> }>();

const lines = defineModel<MaterialLine[]>({ required: true });

type Cell = 'product' | 'description' | 'quantity' | 'weight' | 'unit_cost';

const root = ref<HTMLElement | null>(null);
const search = reactive({ row: null as number | null, results: [] as SearchResult[], highlight: 0, open: false, loading: false, anchor: null as Anchor | null });
let timer: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | undefined;
let token = 0;

const cells = (line: MaterialLine): Cell[] => (line.pricing_type === 'weight' ? ['product', 'description', 'quantity', 'weight', 'unit_cost'] : ['product', 'description', 'quantity', 'unit_cost']);
const input = (row: number, cell: Cell): HTMLInputElement | null => root.value?.querySelector<HTMLInputElement>(`[data-mcell="${row}:${cell}"]`) ?? null;
const error = (index: number, field: string): string | undefined => props.errors[`materials.${index}.${field}`];
const money = (value: number | undefined): string => (value ?? 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function focus(row: number, cell: Cell): void {
    nextTick(() => {
        const target = input(row, cell) ?? input(row, 'product');
        target?.focus();
        target?.select();
    });
}

function addLine(focusIt = true): void {
    lines.value.push(blankMaterial());
    if (focusIt) focus(lines.value.length - 1, 'product');
}

function removeLine(row: number): void {
    lines.value.splice(row, 1);
    if (lines.value.length > 0) focus(Math.min(row, lines.value.length - 1), 'product');
}

function anchorOf(element: HTMLElement | null): Anchor | null {
    if (!element) return null;

    const rect = element.getBoundingClientRect();
    const width = Math.max(rect.width, 320);
    const rtl = getComputedStyle(element).direction === 'rtl';
    const left = rtl ? rect.right - width : rect.left;

    return { top: rect.bottom + 4, left: Math.max(8, Math.min(left, window.innerWidth - width - 8)), width };
}

function closeSearch(): void {
    token++;
    request?.abort();
    clearTimeout(timer);
    Object.assign(search, { open: false, loading: false, results: [], highlight: 0 });
}

function queueSearch(row: number, term: string): void {
    const line = lines.value[row];
    line.product_id = null;
    search.row = row;
    clearTimeout(timer);

    const needle = term.trim();

    if (needle === '') {
        closeSearch();

        return;
    }

    timer = setTimeout(async () => {
        const mine = ++token;
        request?.abort();
        request = new AbortController();
        Object.assign(search, { open: true, loading: true, anchor: anchorOf(input(row, 'product')) });

        try {
            const found = await getJson<{ value: number; label: string }[]>(withQuery(route('accounting.production-orders.materials'), { q: needle }), request.signal);

            if (mine !== token) return;

            search.results = (found ?? []).map((option) => ({ id: Number(option.value), label: option.label }));
            search.highlight = 0;
        } catch {
            // A newer search replaced this one.
        } finally {
            if (mine === token) search.loading = false;
        }
    }, 200);
}

async function choose(row: number, result: SearchResult | undefined): Promise<void> {
    if (!result) return;

    const line = lines.value[row];
    line.product_id = result.id;
    line.product_label = result.label;
    closeSearch();

    try {
        const defaults = await getJson<Partial<MaterialLine> | null>(withQuery(route('accounting.production-orders.material'), { product_id: result.id }));

        if (defaults && lines.value[row] === line) Object.assign(line, defaults);
    } catch {
        // The line keeps what the user typed.
    }

    focus(row, 'quantity');
}

function next(row: number, cell: Cell): void {
    const order = cells(lines.value[row]);
    const at = order.indexOf(cell);

    if (at < order.length - 1) {
        focus(row, order[at + 1]);
    } else if (row < lines.value.length - 1) {
        focus(row + 1, 'product');
    } else if (lines.value[row].product_id) {
        addLine();
    }
}

function onKey(event: KeyboardEvent, row: number, cell: Cell): void {
    const open = cell === 'product' && search.open && search.row === row && search.results.length > 0;

    if (event.key === 'Delete' && event.ctrlKey && props.editable) {
        event.preventDefault();
        removeLine(row);
    } else if (open && event.key === 'ArrowDown') {
        event.preventDefault();
        search.highlight = Math.min(search.highlight + 1, search.results.length - 1);
    } else if (open && event.key === 'ArrowUp') {
        event.preventDefault();
        search.highlight = Math.max(search.highlight - 1, 0);
    } else if (open && event.key === 'Enter') {
        event.preventDefault();
        void choose(row, search.results[search.highlight]);
    } else if (event.key === 'Escape') {
        closeSearch();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        next(row, cell);
    } else if (event.key === 'ArrowDown' && row < lines.value.length - 1) {
        event.preventDefault();
        focus(row + 1, cell);
    } else if (event.key === 'ArrowUp' && row > 0) {
        event.preventDefault();
        focus(row - 1, cell);
    }
}

function onProductBlur(row: number): void {
    setTimeout(() => {
        const line = lines.value[row];

        // An unpicked search leaves the label of the chosen item.
        if (line && !line.product_id) line.product_label = null;
        if (search.row === row) closeSearch();
    }, 150);
}

onBeforeUnmount(() => {
    clearTimeout(timer);
    request?.abort();
});

const cellClass = 'h-control w-full rounded-control border border-line bg-card px-2 text-sm text-ink outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-500/15 disabled:bg-gray-50 dark:disabled:bg-white/[0.03]';
</script>

<template>
    <div ref="root" class="grid gap-3">
        <div class="overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[52rem] border-collapse text-sm">
                <thead class="bg-gray-50 text-xs text-muted dark:bg-white/[0.03]">
                    <tr>
                        <th class="w-10 px-2 py-2 text-center font-medium">#</th>
                        <th class="px-2 py-2 text-start font-medium">{{ t('Item') }}</th>
                        <th class="px-2 py-2 text-start font-medium">{{ t('Description') }}</th>
                        <th class="w-28 px-2 py-2 text-end font-medium">{{ t('Quantity') }}</th>
                        <th class="w-28 px-2 py-2 text-end font-medium">{{ t('Weight') }} ({{ t('kg') }})</th>
                        <th class="w-32 px-2 py-2 text-end font-medium">{{ t('Unit Cost') }} / {{ t('Cost per Kg') }}</th>
                        <th class="w-28 px-2 py-2 text-end font-medium">{{ t('Total') }}</th>
                        <th class="w-10 px-1 py-2"><span class="sr-only">{{ t('Remove') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!lines.length">
                        <td colspan="8" class="px-3 py-6 text-center text-muted">{{ t('No items added yet.') }}</td>
                    </tr>
                    <tr v-for="(line, row) in lines" :key="row" class="border-t border-line align-top">
                        <td class="px-2 py-2 text-center text-muted tabular-nums">{{ row + 1 }}</td>
                        <td class="px-2 py-1.5">
                            <input
                                v-model="line.product_label"
                                type="text"
                                autocomplete="off"
                                :data-mcell="`${row}:product`"
                                :disabled="!editable"
                                :placeholder="t('Search item by name or scan barcode')"
                                :aria-invalid="Boolean(error(row, 'product_id'))"
                                :class="[cellClass, error(row, 'product_id') ? 'border-error-400' : '']"
                                @input="queueSearch(row, ($event.target as HTMLInputElement).value)"
                                @keydown="onKey($event, row, 'product')"
                                @blur="onProductBlur(row)"
                            />
                            <p v-if="error(row, 'product_id')" class="mt-1 text-xs text-error-600">{{ error(row, 'product_id') }}</p>
                        </td>
                        <td class="px-2 py-1.5">
                            <input v-model="line.description" type="text" :data-mcell="`${row}:description`" :disabled="!editable" :class="cellClass" @keydown="onKey($event, row, 'description')" />
                        </td>
                        <td class="px-2 py-1.5">
                            <input v-model="line.quantity" type="text" inputmode="decimal" dir="ltr" :data-mcell="`${row}:quantity`" :disabled="!editable" :class="[cellClass, 'text-end tabular-nums']" @focus="($event.target as HTMLInputElement).select()" @keydown="onKey($event, row, 'quantity')" />
                        </td>
                        <td class="px-2 py-1.5">
                            <input
                                v-if="line.pricing_type === 'weight'"
                                v-model="line.weight"
                                type="text"
                                inputmode="decimal"
                                dir="ltr"
                                :data-mcell="`${row}:weight`"
                                :disabled="!editable"
                                :class="[cellClass, 'text-end tabular-nums']"
                                @focus="($event.target as HTMLInputElement).select()"
                                @keydown="onKey($event, row, 'weight')"
                            />
                            <span v-else class="block px-2 py-2 text-end text-muted">—</span>
                        </td>
                        <td class="px-2 py-1.5">
                            <input v-model="line.unit_cost" type="text" inputmode="decimal" dir="ltr" :data-mcell="`${row}:unit_cost`" :disabled="!editable" :class="[cellClass, 'text-end tabular-nums']" @focus="($event.target as HTMLInputElement).select()" @keydown="onKey($event, row, 'unit_cost')" />
                        </td>
                        <td class="px-2 py-2 text-end font-semibold tabular-nums" dir="ltr">{{ money(totals[row]) }}</td>
                        <td class="px-1 py-1.5 text-center">
                            <button v-if="editable" type="button" class="inline-flex size-8 items-center justify-center rounded-md text-gray-400 hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10" :title="t('Remove')" @click="removeLine(row)">
                                <Trash2 class="size-4" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <GridSearchDropdown
            :open="search.open && search.row !== null"
            :anchor="search.anchor"
            :results="search.results"
            :highlight="search.highlight"
            :loading="search.loading"
            @choose="search.row !== null && choose(search.row, $event)"
            @hover="search.highlight = $event"
        />

        <div v-if="editable" class="flex flex-wrap items-center justify-between gap-3">
            <button type="button" class="inline-flex items-center gap-1.5 rounded-control border border-line bg-card px-3 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10" @click="addLine()">
                <Plus class="size-4" />{{ t('Add Item') }}
            </button>
            <span class="text-xs text-muted">{{ t('Enter: next field / new row · Arrows: move between rows · Ctrl+Delete: remove the row') }}</span>
        </div>
    </div>
</template>
