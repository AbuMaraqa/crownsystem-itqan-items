<script setup lang="ts" generic="Row extends DocumentRow">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import type { ViewField } from '@/Components/table/RecordViewModal.vue';
import Badge from '@/Components/ui/Badge.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { ExternalLink, Printer } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import type { DocumentRow, DocumentTableOptions } from './types';

/**
 * The list of an inventory document (goods receipts, delivery notes, transfers, adjustments):
 * reference, date, the document's own columns, quantity, weight, value (with «Stock Value View»),
 * status and creator; filters for status, warehouse, date and creator plus the document's own.
 * Row actions: the shared four (Info, View, Edit, Delete a draft), then Open (a document that is no
 * longer editable) and Print.
 */
const props = defineProps<{
    title: string;
    /** Route group: `goods-receipts`, `delivery-notes`, `transfers`, `adjustments`. */
    prefix: string;
    /** Permission entity: `Goods Receipt`… */
    entity: string;
    table: TablePayload<Row>;
    options: DocumentTableOptions;
    /** Columns between the date and the quantity. */
    columns: TableColumn[];
    filters: TableFilter[];
    dateLabel?: string;
    /** The date column the server sorts by (`receipt_date`…). */
    dateColumn: string;
}>();

const allColumns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'date', label: props.dateLabel ?? t('Date'), sort: props.dateColumn },
    ...props.columns,
    { key: 'total_quantity', label: t('Quantity'), sort: 'total_quantity', class: 'tabular' },
    { key: 'total_weight', label: t('Weight (kg)'), sort: 'total_weight', class: 'tabular', toggleable: true },
    ...(props.options.canSeeCost ? [{ key: 'total_cost', label: t('Stock Value'), sort: 'total_cost', class: 'tabular', toggleable: true }] : []),
    { key: 'status', label: t('Status'), sort: 'status' },
    createdByColumn(),
]);

const allFilters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { key: 'status', label: t('Status'), options: props.options.statusOptions },
    { key: 'warehouse', label: t('Warehouse'), options: props.options.warehouseOptions },
    { type: 'date-range', key: props.dateColumn, label: props.dateLabel ?? t('Date') },
    ...props.filters,
    { type: 'number-range', key: 'total_quantity', label: t('Quantity') },
    { key: 'created_by', label: t('Created By'), options: props.options.userOptions },
]);

const routes = computed(() => `accounting.inventory.${props.prefix}`);
const canEdit = (row: Row): boolean => row.editable && can(`${props.entity} Update`);

/** A cell as text for the View window: a party shows its name. */
function text(value: unknown): string | number | null {
    if (value !== null && typeof value === 'object' && 'name' in value) return String(value.name);

    return typeof value === 'string' || typeof value === 'number' ? value : null;
}

function viewFields(row: Row): ViewField[] {
    return [
        { label: t('Reference No'), value: row.reference_no },
        { label: props.dateLabel ?? t('Date'), value: row.date },
        ...props.columns.map((column) => ({ label: column.label, value: text((row as Record<string, unknown>)[column.key]) })),
        { label: t('Quantity'), value: row.total_quantity },
        { label: t('Weight (kg)'), value: row.total_weight },
        ...(props.options.canSeeCost ? [{ label: t('Stock Value'), value: row.total_cost }] : []),
        { label: t('Status'), value: row.status?.label },
    ];
}

async function remove(row: Row): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route(`${routes.value}.destroy`, row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="title" :count="table.meta.total">
        <template #actions><slot name="actions" /></template>
    </PageHeader>

    <DataTable :table="table" :columns="allColumns" :filters="allFilters" :bulk-delete-url="can(`${entity} Delete`) ? route(`${routes}.destroy-many`) : undefined" :empty-title="t('No documents yet')">
        <template #cell-reference_no="{ row }">
            <Link :href="route(`${routes}.edit`, row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
        </template>
        <template #cell-date="{ row }"><span dir="ltr">{{ row.date ?? '-' }}</span></template>
        <template #cell-total_quantity="{ row }"><span dir="ltr" class="tabular-nums">{{ row.total_quantity }}</span></template>
        <template #cell-total_weight="{ row }"><span dir="ltr" class="tabular-nums">{{ row.total_weight }}</span></template>
        <template #cell-total_cost="{ row }"><span dir="ltr" class="tabular-nums">{{ row.total_cost ?? '-' }}</span></template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone" dot>{{ row.status.label }}</Badge>
        </template>
        <template v-for="column in columns" :key="column.key" #[`cell-${column.key}`]="{ row }">
            <slot :name="`cell-${column.key}`" :row="row">{{ (row as Record<string, unknown>)[column.key] ?? '-' }}</slot>
        </template>
        <template #empty><slot name="actions" /></template>
        <template #actions="{ row }">
            <RecordActions
                :entity="entity"
                :routes="routes"
                :id="row.id"
                :title="row.reference_no"
                :fields="viewFields(row)"
                :edit-href="route(`${routes}.edit`, row.id)"
                :editable="row.editable"
                :deletable="row.can_delete"
                @delete="remove(row)"
            >
                <DropdownItem v-if="!canEdit(row)" :href="route(`${routes}.edit`, row.id)"><ExternalLink />{{ t('Open document') }}</DropdownItem>
                <DropdownItem external :href="route(`${routes}.pdf`, row.id)"><Printer />{{ t('Print') }}</DropdownItem>
            </RecordActions>
        </template>
    </DataTable>
</template>
