<script setup lang="ts" generic="Row extends DocumentRow">
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Badge from '@/Components/ui/Badge.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import DropdownSeparator from '@/Components/ui/DropdownSeparator.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Info, Pencil, Printer, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordInfoModal from '@modules/Items/resources/js/Pages/parts/RecordInfoModal.vue';

import type { DocumentRow, DocumentTableOptions } from './types';

/**
 * The list of an inventory document (goods receipts, delivery notes, transfers, adjustments):
 * reference, date, the document's own columns, quantity, weight, value (with «Stock Value View»),
 * status and creator; filters for status, warehouse, date and creator plus the document's own.
 * A row opens its document; the menu prints it, shows its history, or deletes a draft.
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
    { key: 'created_by', label: t('Created By'), toggleable: true, hidden: true },
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

const informing = ref<Row | null>(null);
const infoOpen = ref(false);

/** The info window loads when it opens: mount it closed, then open it. */
function showInfo(row: Row): void {
    informing.value = row;
    infoOpen.value = false;
    nextTick(() => (infoOpen.value = true));
}

async function remove(row: Row): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route(`accounting.inventory.${props.prefix}.destroy`, row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="title" :count="table.meta.total">
        <template #actions><slot name="actions" /></template>
    </PageHeader>

    <DataTable :table="table" :columns="allColumns" :filters="allFilters" :empty-title="t('No documents yet')">
        <template #cell-reference_no="{ row }">
            <Link :href="route(`accounting.inventory.${prefix}.edit`, row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
        </template>
        <template #cell-date="{ row }"><span dir="ltr">{{ row.date ?? '-' }}</span></template>
        <template #cell-total_quantity="{ row }"><span dir="ltr" class="tabular-nums">{{ row.total_quantity }}</span></template>
        <template #cell-total_weight="{ row }"><span dir="ltr" class="tabular-nums">{{ row.total_weight }}</span></template>
        <template #cell-total_cost="{ row }"><span dir="ltr" class="tabular-nums">{{ row.total_cost ?? '-' }}</span></template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone" dot>{{ row.status.label }}</Badge>
        </template>
        <template #cell-created_by="{ row }">
            <UserCell v-if="row.created_by" :name="row.created_by.name" :avatar="row.created_by.avatar" :detail="row.created_by.detail" />
            <span v-else class="text-muted">-</span>
        </template>
        <template v-for="column in columns" :key="column.key" #[`cell-${column.key}`]="{ row }">
            <slot :name="`cell-${column.key}`" :row="row">{{ (row as Record<string, unknown>)[column.key] ?? '-' }}</slot>
        </template>
        <template #empty><slot name="actions" /></template>
        <template #actions="{ row }">
            <RowMenu>
                <DropdownItem :href="route(`accounting.inventory.${prefix}.edit`, row.id)"><Pencil />{{ row.editable && can(`${entity} Update`) ? t('Edit') : t('Open') }}</DropdownItem>
                <DropdownItem external :href="route(`accounting.inventory.${prefix}.pdf`, row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                <DropdownItem @select="showInfo(row)"><Info />{{ t('Info') }}</DropdownItem>
                <template v-if="row.can_delete && can(`${entity} Delete`)">
                    <DropdownSeparator />
                    <DropdownItem destructive @select="remove(row)"><Trash2 />{{ t('Delete') }}</DropdownItem>
                </template>
            </RowMenu>
        </template>
    </DataTable>

    <RecordInfoModal v-if="informing" v-model:open="infoOpen" :title="informing.reference_no" :url="route(`accounting.inventory.${prefix}.info`, informing.id)" />
</template>
