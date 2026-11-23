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

interface ReturnRow {
    id: number;
    reference_no: string;
    return_date: string | null;
    sales_invoice: string | null;
    client: UserCellData | null;
    warehouse: string | null;
    currency: string | null;
    exchange_rate: string;
    tax: string | null;
    tax_rate: string;
    total_amount: string;
    total_amount_full: string;
    status: StatusBadge | null;
    notes: string | null;
    deleted: boolean;
    editable: boolean;
}

const props = defineProps<{
    table: TablePayload<ReturnRow>;
    statusOptions: Option[];
    currencyOptions: Option[];
    warehouseOptions: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'return_date', label: t('Return Date'), sort: 'return_date' },
    { key: 'sales_invoice', label: t('Sales Invoice') },
    { key: 'client', label: t('Client') },
    { key: 'warehouse', label: t('Warehouse'), toggleable: true },
    { key: 'currency', label: t('Currency'), toggleable: true },
    { key: 'total_amount', label: t('Total Amount'), sort: 'total_amount', class: 'tabular' },
    { key: 'status', label: t('Status'), sort: 'status' },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'sales_invoice', label: t('Sales Invoice') },
    { type: 'text', key: 'client', label: t('Client') },
    { key: 'warehouse', label: t('Warehouse'), options: props.warehouseOptions },
    { type: 'text', key: 'notes', label: t('Notes'), placeholder: t('Search by notes') },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    { type: 'date-range', key: 'return_date', label: t('Return Date') },
    { type: 'number-range', key: 'total_amount', label: t('Total Amount') },
    {
        key: 'trashed',
        label: t('Deleted records'),
        options: [
            { value: 'with', label: t('With deleted records') },
            { value: 'only', label: t('Only deleted records') },
        ],
    },
]);

const canOpen = (row: ReturnRow): boolean => !row.deleted && can('Sales Return Update');

function viewFields(row: ReturnRow): ViewField[] {
    return [
        { label: t('Reference No'), value: row.reference_no },
        { label: t('Return Date'), value: row.return_date },
        { label: t('Status'), value: row.status?.label },
        { label: t('Sales Invoice'), value: row.sales_invoice },
        { label: t('Client'), value: row.client?.name },
        { label: t('Warehouse'), value: row.warehouse },
        { label: t('Currency'), value: row.currency },
        { label: t('Exchange Rate'), value: row.exchange_rate },
        { label: t('Tax'), value: row.tax },
        { label: t('Tax Rate'), value: row.tax_rate },
        { label: t('Total Amount'), value: row.total_amount_full },
        { label: t('Notes'), value: row.notes },
    ];
}

async function remove(row: ReturnRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.sales-returns.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Sales Returns')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Sales Return Create')" variant="primary" :href="route('accounting.sales-returns.add')">{{ t('Add Sales Return') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Sales Return Delete') ? route('accounting.sales-returns.destroy-many') : undefined" :empty-title="t('No sales returns found')">
        <template #cell-reference_no="{ row }">
            <Link v-if="canOpen(row)" :href="route('accounting.sales-returns.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" :class="{ 'text-muted line-through': row.deleted }">{{ row.reference_no }}</span>
        </template>
        <template #cell-return_date="{ row }"><span dir="ltr">{{ row.return_date ?? '-' }}</span></template>
        <template #cell-sales_invoice="{ row }"><span dir="ltr">{{ row.sales_invoice ?? '-' }}</span></template>
        <template #cell-client="{ row }">
            <UserCell v-if="row.client" :name="row.client.name" :avatar="row.client.avatar" :detail="row.client.detail" />
            <span v-else class="text-muted">-</span>
        </template>
        <template #cell-warehouse="{ row }">{{ row.warehouse ?? '-' }}</template>
        <template #cell-currency="{ row }"><span dir="ltr">{{ row.currency ?? '-' }}</span></template>
        <template #cell-total_amount="{ row }"><span dir="ltr" class="tabular">{{ row.total_amount }}</span></template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone" dot>{{ row.status.label }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can('Sales Return Create')" variant="primary" :href="route('accounting.sales-returns.add')">{{ t('Add Sales Return') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Sales Return"
                routes="accounting.sales-returns"
                :id="row.id"
                :title="row.reference_no"
                :fields="viewFields(row)"
                :edit-href="route('accounting.sales-returns.edit', row.id)"
                :editable="row.editable"
                :deletable="row.editable"
                @delete="remove(row)"
            >
                <template v-if="!row.deleted" #default>
                    <DropdownItem v-if="!row.editable && canOpen(row)" :href="route('accounting.sales-returns.edit', row.id)"><ExternalLink />{{ t('Open document') }}</DropdownItem>
                    <DropdownItem v-if="can('Sales Return View')" external :href="route('accounting.sales-returns.pdf', row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                </template>
            </RecordActions>
        </template>
    </DataTable>
</template>
