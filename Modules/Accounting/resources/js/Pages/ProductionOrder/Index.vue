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
import { ExternalLink } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import type { StatusBadge } from '../parts/statusBadge';
import type { Option, UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    reference_no: string;
    order_date: string | null;
    due_date: string | null;
    sales_order: { id: number; reference_no: string } | null;
    client: UserCellData | null;
    produced_quantity: string;
    total_cost: string;
    unit_cost: string;
    sales_amount: string;
    margin: string;
    margin_negative: boolean;
    currency: string | null;
    status: StatusBadge | null;
    notes: string | null;
    editable: boolean;
    can_delete: boolean;
}

const props = defineProps<{ table: TablePayload<Row>; statusOptions: Option[]; currencyOptions: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'order_date', label: t('Order Date'), sort: 'order_date' },
    { key: 'due_date', label: t('Due Date'), sort: 'due_date', toggleable: true },
    { key: 'sales_order', label: t('Sales Order') },
    { key: 'client', label: t('Client') },
    { key: 'produced_quantity', label: t('Produced Quantity'), class: 'tabular' },
    { key: 'total_cost', label: t('Total Cost'), sort: 'total_cost', class: 'tabular' },
    { key: 'unit_cost', label: t('Unit Cost'), sort: 'unit_cost', class: 'tabular' },
    { key: 'sales_amount', label: t('Sales Value'), class: 'tabular', toggleable: true },
    { key: 'margin', label: t('Margin'), class: 'tabular', toggleable: true },
    { key: 'currency', label: t('Currency'), toggleable: true },
    { key: 'status', label: t('Status'), sort: 'status' },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'sales_order', label: t('Sales Order') },
    { type: 'text', key: 'client', label: t('Client') },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    { type: 'date-range', key: 'order_date', label: t('Order Date') },
    { type: 'date-range', key: 'due_date', label: t('Due Date') },
    { type: 'number-range', key: 'total_cost', label: t('Total Cost') },
]);

function viewFields(row: Row): ViewField[] {
    return [
        { label: t('Reference No'), value: row.reference_no },
        { label: t('Order Date'), value: row.order_date },
        { label: t('Due Date'), value: row.due_date },
        { label: t('Status'), value: row.status?.label },
        { label: t('Sales Order'), value: row.sales_order?.reference_no },
        { label: t('Client'), value: row.client?.name },
        { label: t('Produced Quantity'), value: row.produced_quantity },
        { label: t('Total Cost'), value: row.total_cost },
        { label: t('Unit Cost'), value: row.unit_cost },
        { label: t('Sales Value'), value: row.sales_amount },
        { label: t('Margin'), value: row.margin },
        { label: t('Currency'), value: row.currency },
        { label: t('Notes'), value: row.notes },
    ];
}

async function remove(row: Row): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.production-orders.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Production Orders')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Production Order Create')" variant="primary" :href="route('accounting.production-orders.add')">{{ t('Add Production Order') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No production orders found')">
        <template #cell-reference_no="{ row }">
            <Link v-if="can('Production Order Update')" :href="route('accounting.production-orders.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr">{{ row.reference_no }}</span>
        </template>
        <template #cell-order_date="{ row }"><span dir="ltr">{{ row.order_date ?? '-' }}</span></template>
        <template #cell-due_date="{ row }"><span dir="ltr">{{ row.due_date ?? '-' }}</span></template>
        <template #cell-sales_order="{ row }">
            <template v-if="row.sales_order">
                <a v-if="can('Sales Order Update')" :href="route('accounting.sales-orders.edit', row.sales_order.id)" class="text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.sales_order.reference_no }}</a>
                <span v-else dir="ltr">{{ row.sales_order.reference_no }}</span>
            </template>
            <span v-else class="text-muted">-</span>
        </template>
        <template #cell-client="{ row }">
            <UserCell v-if="row.client" :name="row.client.name" :avatar="row.client.avatar" :detail="row.client.detail" />
            <span v-else class="text-muted">-</span>
        </template>
        <template #cell-produced_quantity="{ row }"><span dir="ltr" class="tabular">{{ row.produced_quantity }}</span></template>
        <template #cell-total_cost="{ row }"><span dir="ltr" class="tabular">{{ row.total_cost }}</span></template>
        <template #cell-unit_cost="{ row }"><span dir="ltr" class="tabular">{{ row.unit_cost }}</span></template>
        <template #cell-sales_amount="{ row }"><span dir="ltr" class="tabular">{{ row.sales_amount }}</span></template>
        <template #cell-margin="{ row }">
            <span dir="ltr" class="tabular font-medium" :class="row.margin_negative ? 'text-danger' : 'text-success'">{{ row.margin }}</span>
        </template>
        <template #cell-currency="{ row }"><span dir="ltr">{{ row.currency ?? '-' }}</span></template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone" dot>{{ row.status.label }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can('Production Order Create')" variant="primary" :href="route('accounting.production-orders.add')">{{ t('Add Production Order') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Production Order"
                routes="accounting.production-orders"
                :id="row.id"
                :title="row.reference_no"
                :fields="viewFields(row)"
                :edit-href="route('accounting.production-orders.edit', row.id)"
                :editable="row.editable"
                :deletable="row.can_delete"
                @delete="remove(row)"
            >
                <template v-if="!row.editable && can('Production Order Update')" #default>
                    <DropdownItem :href="route('accounting.production-orders.edit', row.id)"><ExternalLink />{{ t('Open document') }}</DropdownItem>
                </template>
            </RecordActions>
        </template>
    </DataTable>
</template>
