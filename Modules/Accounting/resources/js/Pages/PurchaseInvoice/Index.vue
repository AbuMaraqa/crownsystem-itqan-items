<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import type { ViewField } from '@/Components/table/RecordViewModal.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { ExternalLink, Printer } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import type { StatusBadge } from '../parts/statusBadge';
import type { Option, UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface InvoiceRow {
    id: number;
    reference_no: string;
    invoice_date: string | null;
    due_date: string | null;
    client: UserCellData | null;
    warehouse: string | null;
    currency: string | null;
    total_amount: string;
    total_amount_full: string;
    exchange_rate: string;
    tax: string | null;
    tax_rate: string;
    journal_entry: string | null;
    status: StatusBadge | null;
    notes: string | null;
    deleted: boolean;
    editable: boolean;
}

const props = defineProps<{
    table: TablePayload<InvoiceRow>;
    statusOptions: Option[];
    currencyOptions: Option[];
    warehouseOptions: Option[];
    taxOptions: Option[];
    userOptions: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'invoice_date', label: t('Invoice Date'), sort: 'invoice_date' },
    { key: 'client', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse'), toggleable: true },
    { key: 'currency', label: t('Currency'), toggleable: true },
    { key: 'total_amount', label: t('Balance Due'), sort: 'total_amount', class: 'tabular' },
    { key: 'exchange_rate', label: t('Exchange Rate'), class: 'tabular', toggleable: true },
    { key: 'tax', label: t('Tax'), toggleable: true },
    { key: 'due_date', label: t('Due Date'), sort: 'due_date', toggleable: true, hidden: true },
    { key: 'status', label: t('Status'), sort: 'status' },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'client', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse'), options: props.warehouseOptions },
    { type: 'text', key: 'notes', label: t('Notes'), placeholder: t('Search by notes') },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    {
        key: 'tax_state',
        label: t('Tax Status'),
        options: [
            { value: 'with_tax', label: t('With Tax') },
            { value: 'without_tax', label: t('No tax') },
        ],
    },
    { key: 'tax', label: t('Tax Type'), options: props.taxOptions },
    { type: 'text', key: 'purchase_order', label: t('Purchase Order') },
    { type: 'text', key: 'item_barcode', label: t('Barcode'), placeholder: t('Search by barcode') },
    { type: 'text', key: 'item_description', label: t('Description'), placeholder: t('Search by item description') },
    { type: 'date-range', key: 'invoice_date', label: t('Invoice Date') },
    { type: 'date-range', key: 'due_date', label: t('Due Date') },
    {
        key: 'document_state',
        label: t('Invoice State'),
        options: [
            { value: 'with_items', label: t('With Items') },
            { value: 'without_items', label: t('Without Items') },
            { value: 'with_journal', label: t('With Journal Entry') },
            { value: 'without_journal', label: t('Without Journal Entry') },
        ],
    },
    {
        key: 'due_state',
        label: t('Due State'),
        options: [
            { value: 'overdue', label: t('Overdue') },
            { value: 'due_today', label: t('Due Today') },
            { value: 'upcoming', label: t('Upcoming Due') },
            { value: 'no_due_date', label: t('No Due Date') },
        ],
    },
    { type: 'number-range', key: 'exchange_rate', label: t('Exchange Rate') },
    { type: 'number-range', key: 'tax_rate', label: t('Tax Rate') },
    { type: 'number-range', key: 'sub_total', label: t('Subtotal') },
    { type: 'number-range', key: 'items_discount', label: t('Items Discount') },
    { type: 'number-range', key: 'invoice_discount', label: t('Invoice Discount') },
    { type: 'number-range', key: 'tax_amount', label: t('Tax Amount') },
    { type: 'number-range', key: 'total_amount', label: t('Balance Due') },
    { type: 'number-range', key: 'item_quantity', label: t('Quantity') },
    { type: 'number-range', key: 'item_unit_price', label: t('Price') },
    { type: 'number-range', key: 'item_discount_rate', label: t('Item Discount Rate') },
    { type: 'number-range', key: 'item_discount_amount', label: t('Item Discount Amount') },
    { type: 'number-range', key: 'item_line_total', label: t('Line Total') },
    { type: 'text', key: 'journal_entry', label: t('Entry Number'), placeholder: t('Search by journal entry number') },
    { type: 'date-range', key: 'posted_at', label: t('Posted Date') },
    { type: 'date-range', key: 'created_at', label: t('Created At') },
    { key: 'created_by', label: t('Created By'), options: props.userOptions },
    {
        key: 'trashed',
        label: t('Deleted records'),
        options: [
            { value: 'with', label: t('With deleted records') },
            { value: 'only', label: t('Only deleted records') },
        ],
    },
]);

function viewFields(row: InvoiceRow): ViewField[] {
    return [
        { label: t('Reference No'), value: row.reference_no },
        { label: t('Invoice Date'), value: row.invoice_date },
        { label: t('Status'), value: row.status?.label },
        { label: t('Supplier'), value: row.client?.name },
        { label: t('Warehouse'), value: row.warehouse },
        { label: t('Currency'), value: row.currency },
        { label: t('Exchange Rate'), value: row.exchange_rate },
        { label: t('Tax'), value: row.tax },
        { label: t('Tax Rate'), value: row.tax_rate },
        { label: t('Balance Due'), value: row.total_amount_full },
        { label: t('Notes'), value: row.notes },
    ];
}

async function remove(row: InvoiceRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.purchase-invoices.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Purchase Invoices')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Purchase Invoice Create')" variant="primary" :href="route('accounting.purchase-invoices.add')">{{ t('Add Purchase Invoice') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Purchase Invoice Delete') ? route('accounting.purchase-invoices.destroy-many') : undefined" :empty-title="t('No purchase invoices found')">
        <template #cell-reference_no="{ row }">
            <Link v-if="!row.deleted" :href="route('accounting.purchase-invoices.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" class="text-muted line-through">{{ row.reference_no }}</span>
        </template>
        <template #cell-invoice_date="{ row }"><span dir="ltr">{{ row.invoice_date ?? '-' }}</span></template>
        <template #cell-due_date="{ row }"><span dir="ltr">{{ row.due_date ?? '-' }}</span></template>
        <template #cell-client="{ row }">
            <UserCell v-if="row.client" :name="row.client.name" :avatar="row.client.avatar" :detail="row.client.detail" />
            <span v-else class="text-muted">-</span>
        </template>
        <template #cell-warehouse="{ row }">{{ row.warehouse ?? '-' }}</template>
        <template #cell-currency="{ row }"><span dir="ltr">{{ row.currency ?? '-' }}</span></template>
        <template #cell-total_amount="{ row }"><span dir="ltr" class="tabular">{{ row.total_amount }}</span></template>
        <template #cell-exchange_rate="{ row }"><span dir="ltr" class="tabular">{{ row.exchange_rate }}</span></template>
        <template #cell-tax="{ row }">{{ row.tax ?? t('No tax') }}</template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone" dot>{{ row.status.label }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can('Purchase Invoice Create')" variant="primary" :href="route('accounting.purchase-invoices.add')">{{ t('Add Purchase Invoice') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Purchase Invoice"
                routes="accounting.purchase-invoices"
                :id="row.id"
                :title="row.reference_no"
                :fields="viewFields(row)"
                :edit-href="route('accounting.purchase-invoices.edit', row.id)"
                :editable="row.editable"
                :deletable="row.editable"
                @delete="remove(row)"
            >
                <template v-if="!row.deleted" #default>
                    <DropdownItem v-if="!(row.editable && can('Purchase Invoice Update'))" :href="route('accounting.purchase-invoices.edit', row.id)"><ExternalLink />{{ t('Open document') }}</DropdownItem>
                    <DropdownItem v-if="can('Purchase Invoice View')" external :href="route('accounting.purchase-invoices.pdf', row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                </template>
            </RecordActions>
        </template>
    </DataTable>
</template>
