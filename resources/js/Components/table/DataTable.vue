<script setup lang="ts" generic="Row extends { id: number | string }">
import { router } from '@inertiajs/vue3';
import type Sortable from 'sortablejs';
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, toRef, watch } from 'vue';

import { ArrowDown, ArrowUp, ArrowUpDown, GripVertical, Search } from '@/lib/icons';

import { t } from '@/lib/i18n';
import { useDataTable } from '@/lib/useDataTable';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import TableColumnsMenu from './TableColumnsMenu.vue';
import TableEmpty from './TableEmpty.vue';
import TableFilterChips from './TableFilterChips.vue';
import TableFiltersMenu from './TableFiltersMenu.vue';
import TablePagination from './TablePagination.vue';
import UserCell from './UserCell.vue';
import type { UserCellData } from '@/types/user';

const props = withDefaults(
    defineProps<{
        table: TablePayload<Row>;
        columns: TableColumn[];
        /** Every admin table declares its filters (see tests/Unit/Architecture/TableFiltersTest). */
        filters: TableFilter[];
        heading?: string;
        searchable?: boolean;
        selectable?: boolean;
        emptyTitle?: string;
        emptyText?: string;
        /** Props to reload; other page props stay untouched. */
        only?: string[];
        /**
         * The `<routes>.reorder` URL of a table the user may reorder (`AdminTable::reorderable()`).
         * Rows get a drag handle while the table is sorted by its order column.
         */
        reorderUrl?: string;
    }>(),
    { searchable: true, selectable: false, only: () => ['table', 'flash'] },
);

const state = useDataTable(toRef(props, 'table'), toRef(props, 'filters'), toRef(props, 'columns'), toRef(props, 'only'));
const { loading, values, search, visibleColumns, toggleableColumns, hidden, chips, visit, setFilter, removeFilter, resetFilters, toggleSort, toggleColumn } = state;

const selected = ref<(number | string)[]>([]);

// The rows as shown: a drop reorders them at once, the server's answer replaces them.
const rows = shallowRef<Row[]>([...props.table.data]);
const body = shallowRef<HTMLElement | null>(null);
const draggable = computed(() => !!props.reorderUrl && !!props.table.reorder && props.table.query.sort === props.table.reorder && props.table.data.length > 1);
let sortable: Sortable | null = null;

async function bindSortable(): Promise<void> {
    sortable?.destroy();
    sortable = null;

    if (!draggable.value) return;

    const { default: Sortable } = await import('sortablejs');
    await nextTick();

    if (!body.value || !draggable.value) return;

    sortable = Sortable.create(body.value, {
        handle: '[data-row-handle]',
        animation: 150,
        ghostClass: 'opacity-40',
        onEnd: ({ item, from, oldIndex, newIndex }) => {
            if (oldIndex === undefined || newIndex === undefined || oldIndex === newIndex) return;

            // Vue owns these nodes: put the row back and let the new order re-render the body.
            from.removeChild(item);
            from.insertBefore(item, from.children[oldIndex] ?? null);

            const ordered = [...rows.value];
            ordered.splice(newIndex, 0, ...ordered.splice(oldIndex, 1));
            rows.value = ordered;

            router.post(props.reorderUrl!, { ids: ordered.map((row) => row.id) }, { preserveScroll: true, preserveState: true, only: props.only });
        },
    });
}

watch(draggable, bindSortable);
watch(body, bindSortable);
onBeforeUnmount(() => sortable?.destroy());

const userCell = (row: Row, key: string): UserCellData | null => ((row as Record<string, unknown>)[key] as UserCellData | null) ?? null;
const sort = computed(() => props.table.query.sort);
const allSelected = computed(() => props.table.data.length > 0 && props.table.data.every((row) => selected.value.includes(row.id)));
const isFiltered = computed(() => chips.value.length > 0 || !!search.value);

function toggleAll(): void {
    selected.value = allSelected.value ? [] : props.table.data.map((row) => row.id);
}

function toggleRow(id: number | string): void {
    selected.value = selected.value.includes(id) ? selected.value.filter((item) => item !== id) : [...selected.value, id];
}

function ariaSort(column: TableColumn): 'ascending' | 'descending' | undefined {
    if (!column.sort || sort.value.replace('-', '') !== column.sort) return undefined;

    return sort.value.startsWith('-') ? 'descending' : 'ascending';
}

watch(
    () => props.table.data,
    () => {
        rows.value = [...props.table.data];
        const visible = props.table.data.map((row) => row.id);
        selected.value = selected.value.filter((id) => visible.includes(id));
    },
);

defineExpose({ clearSelection: () => (selected.value = []) });
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-line bg-card shadow-card">
        <div class="flex min-h-14 flex-wrap items-center gap-2.5 border-b border-line px-5 py-2.5">
            <h2 v-if="heading" class="me-auto text-base font-semibold text-gray-900 dark:text-white">{{ heading }} <span class="tabular font-normal text-muted">({{ table.meta.total.toLocaleString('en') }})</span></h2>

            <div v-if="selectable && selected.length" class="flex items-center gap-2 text-sm" :class="{ 'me-auto': !heading }">
                <span class="text-muted">{{ t(':count selected', { count: selected.length }) }}</span>
                <slot name="bulk" :selected="selected" />
            </div>

            <label v-if="searchable" class="relative min-w-44 flex-1 sm:max-w-64" :class="{ 'ms-auto': !heading && !selected.length }">
                <span class="sr-only">{{ t('Search...') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                <input v-model="search" type="search" :placeholder="t('Search...')" class="h-control w-full rounded-control border border-gray-200 bg-card ps-9 pe-3 text-[13px] shadow-theme-xs outline-none placeholder:text-gray-400 focus:border-brand-300 focus:shadow-focus-ring dark:border-gray-800 dark:focus:border-brand-500/50" />
            </label>

            <TableFiltersMenu v-if="filters.length" :filters="filters" :values="values" :active-count="chips.length" @change="setFilter" @reset="resetFilters" />
            <TableColumnsMenu v-if="toggleableColumns.length" :columns="toggleableColumns" :hidden="hidden" @toggle="toggleColumn" />
        </div>

        <TableFilterChips :chips="chips" @remove="removeFilter" @reset="resetFilters" />

        <div class="overflow-x-auto transition-opacity" :class="{ 'opacity-50': loading }" :aria-busy="loading">
            <table class="w-full border-collapse text-sm [&_td+td]:border-s [&_td+td]:border-line [&_th+th]:border-s [&_th+th]:border-line">
                <thead class="bg-gray-100/40 dark:bg-white/[0.02]">
                    <tr class="border-b border-line text-[13px] text-gray-600 dark:text-gray-400">
                        <th v-if="draggable" class="w-10 px-2 py-3"><span class="sr-only">{{ t('Row order') }}</span></th>
                        <th v-if="selectable" class="w-12 px-5 py-3 text-start">
                            <input type="checkbox" :checked="allSelected" :aria-label="t('Select all')" class="size-4 rounded accent-brand-500" @change="toggleAll" />
                        </th>
                        <th v-for="column in visibleColumns" :key="column.key" class="px-5 py-2.5 text-start font-normal whitespace-nowrap" :class="column.class" :aria-sort="ariaSort(column)">
                            <button v-if="column.sort" type="button" class="group inline-flex items-center gap-1.5 hover:text-gray-900 dark:hover:text-white" @click="toggleSort(column)">
                                {{ column.label }}
                                <ArrowUp v-if="sort === column.sort" class="size-3 text-ink" />
                                <ArrowDown v-else-if="sort === `-${column.sort}`" class="size-3 text-ink" />
                                <ArrowUpDown v-else class="size-3 opacity-40 group-hover:opacity-100" />
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </th>
                        <th v-if="$slots.actions" class="w-px px-3 py-3 text-center font-medium whitespace-nowrap">{{ t('Actions') }}</th>
                    </tr>
                </thead>
                <tbody ref="body">
                    <tr v-for="row in rows" :key="row.id" class="border-b border-line transition-colors last:border-b-0 hover:bg-gray-50/70 dark:hover:bg-white/[0.02]" :class="{ 'bg-brand-50/40 dark:bg-brand-500/10': selected.includes(row.id) }">
                        <td v-if="draggable" class="w-10 px-2 py-3 text-center">
                            <button type="button" data-row-handle class="inline-flex size-7 cursor-grab items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-ink active:cursor-grabbing dark:hover:bg-white/5" :title="t('Drag to reorder')" :aria-label="t('Drag to reorder')">
                                <GripVertical class="size-4" />
                            </button>
                        </td>
                        <td v-if="selectable" class="px-5 py-3">
                            <input type="checkbox" :checked="selected.includes(row.id)" :aria-label="t('Select row')" class="size-4 rounded accent-brand-500" @change="toggleRow(row.id)" />
                        </td>
                        <td v-for="column in visibleColumns" :key="column.key" class="px-5 py-3 text-gray-800 dark:text-gray-200" :class="column.class">
                            <slot :name="`cell-${column.key}`" :row="row">
                                <template v-if="column.type === 'user'">
                                    <UserCell v-if="userCell(row, column.key)" v-bind="userCell(row, column.key)!" />
                                    <span v-else class="text-muted">-</span>
                                </template>
                                <template v-else>{{ (row as Record<string, unknown>)[column.key] }}</template>
                            </slot>
                        </td>
                        <td v-if="$slots.actions" class="w-px px-3 py-2 text-center whitespace-nowrap"><slot name="actions" :row="row" /></td>
                    </tr>
                </tbody>
            </table>

            <TableEmpty v-if="table.data.length === 0" :title="emptyTitle" :text="emptyText" :filtered="isFiltered" @reset="resetFilters(); search = ''">
                <slot v-if="$slots.empty" name="empty" />
            </TableEmpty>
        </div>

        <TablePagination v-if="table.meta.total > 0" :meta="table.meta" @page="(page) => visit({ page })" @per-page="(perPage) => visit({ perPage, page: 1 })" />
    </div>
</template>
