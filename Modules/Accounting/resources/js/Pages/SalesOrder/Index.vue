<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import DropdownSeparator from '@/Components/ui/DropdownSeparator.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Eye, Info, Pencil, Printer, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordInfoModal from '@modules/Items/resources/js/Pages/parts/RecordInfoModal.vue';
import RecordViewModal from '@modules/Items/resources/js/Pages/parts/RecordViewModal.vue';

import type { StatusBadge } from '../parts/statusBadge';
import type { Option, UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface OrderRow {
    id: number;
    reference_no: string;
    order_date: string | null;
    expected_delivery_date: string | null;
    sales_quotation: string | null;
    client: UserCellData | null;
    warehouse: string | null;
    currency: string | null;
    total_amount: string;
    total_amount_full: string;
    status: StatusBadge | null;
    notes: string | null;
    deleted: boolean;
    editable: boolean;
    can_delete: boolean;
}

const props = defineProps<{
    table: TablePayload<OrderRow>;
    statusOptions: Option[];
    currencyOptions: Option[];
    warehouseOptions: Option[];
    userOptions: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'order_date', label: t('Order Date'), sort: 'order_date' },
    { key: 'expected_delivery_date', label: t('Expected Delivery Date'), sort: 'expected_delivery_date', toggleable: true },
    { key: 'sales_quotation', label: t('Sales Quotation'), toggleable: true },
    { key: 'client', label: t('Client') },
    { key: 'warehouse', label: t('Warehouse'), toggleable: true },
    { key: 'currency', label: t('Currency'), toggleable: true },
    { key: 'total_amount', label: t('Total Amount'), sort: 'total_amount', class: 'tabular' },
    { key: 'status', label: t('Status'), sort: 'status' },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'sales_quotation', label: t('Sales Quotation') },
    { type: 'text', key: 'client', label: t('Client') },
    { key: 'warehouse', label: t('Warehouse'), options: props.warehouseOptions },
    { type: 'text', key: 'notes', label: t('Notes'), placeholder: t('Search by notes') },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    { type: 'text', key: 'item_description', label: t('Item'), placeholder: t('Search by item description') },
    { type: 'text', key: 'item_barcode', label: t('Barcode'), placeholder: t('Search by barcode') },
    { type: 'date-range', key: 'order_date', label: t('Order Date') },
    { type: 'date-range', key: 'expected_delivery_date', label: t('Expected Delivery Date') },
    {
        key: 'delivery_state',
        label: t('Delivery State'),
        options: [
            { value: 'overdue', label: t('Overdue') },
            { value: 'due_today', label: t('Due Today') },
            { value: 'upcoming', label: t('Upcoming Due') },
            { value: 'no_date', label: t('No Date') },
        ],
    },
    { type: 'number-range', key: 'sub_total', label: t('Subtotal') },
    { type: 'number-range', key: 'order_discount', label: t('Order Discount') },
    { type: 'number-range', key: 'total_amount', label: t('Total Amount') },
    {
        key: 'items_state',
        label: t('Items State'),
        options: [
            { value: 'with_items', label: t('With Items') },
            { value: 'without_items', label: t('Without Items') },
        ],
    },
    {
        key: 'source_state',
        label: t('Source State'),
        options: [
            { value: 'from_quotation', label: t('From Sales Quotation') },
            { value: 'manual', label: t('Manual') },
        ],
    },
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

const canOpen = (row: OrderRow): boolean => !row.deleted && can('Sales Order Update');

const viewing = ref<OrderRow | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});

const informing = ref<OrderRow | null>(null);
const infoOpen = ref(false);

/** The info window loads when it opens: mount it closed, then open it. */
function showInfo(row: OrderRow): void {
    informing.value = row;
    infoOpen.value = false;
    nextTick(() => (infoOpen.value = true));
}

async function remove(row: OrderRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.sales-orders.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Sales Orders')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Sales Order Create')" variant="primary" :href="route('accounting.sales-orders.add')">{{ t('Add Sales Order') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No sales orders found')">
        <template #cell-reference_no="{ row }">
            <Link v-if="canOpen(row)" :href="route('accounting.sales-orders.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" :class="{ 'text-muted line-through': row.deleted }">{{ row.reference_no }}</span>
        </template>
        <template #cell-order_date="{ row }"><span dir="ltr">{{ row.order_date ?? '-' }}</span></template>
        <template #cell-expected_delivery_date="{ row }"><span dir="ltr">{{ row.expected_delivery_date ?? '-' }}</span></template>
        <template #cell-sales_quotation="{ row }">
            <span v-if="row.sales_quotation" dir="ltr">{{ row.sales_quotation }}</span>
            <span v-else class="text-muted">{{ t('Manual') }}</span>
        </template>
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
            <Button v-if="can('Sales Order Create')" variant="primary" :href="route('accounting.sales-orders.add')">{{ t('Add Sales Order') }}</Button>
        </template>
        <template #actions="{ row }">
            <RowMenu>
                <DropdownItem v-if="can('Sales Order View')" @select="viewing = row"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="can('Sales Order Info') && !row.deleted" @select="showInfo(row)"><Info />{{ t('Info') }}</DropdownItem>
                <DropdownItem v-if="canOpen(row)" :href="route('accounting.sales-orders.edit', row.id)"><Pencil />{{ row.editable ? t('Edit') : t('Open') }}</DropdownItem>
                <DropdownItem v-if="can('Sales Order View') && !row.deleted" external :href="route('accounting.sales-orders.pdf', row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                <template v-if="row.can_delete && can('Sales Order Delete')">
                    <DropdownSeparator />
                    <DropdownItem destructive @select="remove(row)"><Trash2 />{{ t('Delete') }}</DropdownItem>
                </template>
            </RowMenu>
        </template>
    </DataTable>

    <RecordViewModal
        v-if="viewing"
        v-model:open="viewOpen"
        :title="viewing.reference_no"
        :fields="[
            { label: t('Reference No'), value: viewing.reference_no },
            { label: t('Sales Quotation'), value: viewing.sales_quotation ?? t('Manual') },
            { label: t('Order Date'), value: viewing.order_date },
            { label: t('Status'), value: viewing.status?.label },
            { label: t('Client'), value: viewing.client?.name },
            { label: t('Warehouse'), value: viewing.warehouse },
            { label: t('Currency'), value: viewing.currency },
            { label: t('Total Amount'), value: viewing.total_amount_full },
            { label: t('Notes'), value: viewing.notes },
        ]"
    />
    <RecordInfoModal v-if="informing" v-model:open="infoOpen" :title="informing.reference_no" :url="route('accounting.sales-orders.info', informing.id)" />
</template>
