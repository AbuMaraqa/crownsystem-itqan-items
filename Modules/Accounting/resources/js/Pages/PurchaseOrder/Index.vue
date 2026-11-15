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
    client: UserCellData | null;
    warehouse: string | null;
    delivery_type: string | null;
    items_count: number;
    total_amount: string;
    status: StatusBadge | null;
    notes: string | null;
    deleted: boolean;
    editable: boolean;
    can_delete: boolean;
}

const props = defineProps<{
    table: TablePayload<OrderRow>;
    statusOptions: Option[];
    deliveryTypeOptions: Option[];
    warehouseOptions: Option[];
    userOptions: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'order_date', label: t('Order Date'), sort: 'order_date' },
    { key: 'expected_delivery_date', label: t('Expected Delivery Date'), sort: 'expected_delivery_date' },
    { key: 'client', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse'), toggleable: true },
    { key: 'delivery_type', label: t('Delivery Type'), toggleable: true, hidden: true },
    { key: 'total_amount', label: t('Total Amount'), sort: 'total_amount', class: 'tabular', toggleable: true },
    { key: 'status', label: t('Status'), sort: 'status' },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'client', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse'), options: props.warehouseOptions },
    { type: 'text', key: 'notes', label: t('Notes'), placeholder: t('Search by notes') },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'delivery_type', label: t('Delivery Type'), options: props.deliveryTypeOptions },
    { type: 'text', key: 'item_barcode', label: t('Barcode'), placeholder: t('Search by barcode') },
    { type: 'text', key: 'item_description', label: t('Description'), placeholder: t('Search by item description') },
    { type: 'date-range', key: 'order_date', label: t('Order Date') },
    { type: 'date-range', key: 'expected_delivery_date', label: t('Expected Delivery Date') },
    { type: 'number-range', key: 'total_amount', label: t('Total Amount') },
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

const viewing = ref<OrderRow | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});

const informing = ref<OrderRow | null>(null);
const infoOpen = ref(false);

function showInfo(row: OrderRow): void {
    informing.value = row;
    infoOpen.value = false;
    nextTick(() => (infoOpen.value = true));
}

async function remove(row: OrderRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.purchase-orders.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Purchase Orders')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Purchase Order Create')" variant="primary" :href="route('accounting.purchase-orders.add')">{{ t('Add Purchase Order') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No purchase orders found')">
        <!-- Every order opens from its number: an invoiced one read only. -->
        <template #cell-reference_no="{ row }">
            <Link v-if="!row.deleted" :href="route('accounting.purchase-orders.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" class="text-muted line-through">{{ row.reference_no }}</span>
        </template>
        <template #cell-order_date="{ row }"><span dir="ltr">{{ row.order_date ?? '-' }}</span></template>
        <template #cell-expected_delivery_date="{ row }"><span dir="ltr">{{ row.expected_delivery_date ?? '-' }}</span></template>
        <template #cell-client="{ row }">
            <UserCell v-if="row.client" :name="row.client.name" :avatar="row.client.avatar" :detail="row.client.detail" />
            <span v-else class="text-muted">-</span>
        </template>
        <template #cell-warehouse="{ row }">{{ row.warehouse ?? '-' }}</template>
        <template #cell-delivery_type="{ row }">{{ row.delivery_type ?? '-' }}</template>
        <template #cell-total_amount="{ row }"><span dir="ltr" class="tabular">{{ row.total_amount }}</span></template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone" dot>{{ row.status.label }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can('Purchase Order Create')" variant="primary" :href="route('accounting.purchase-orders.add')">{{ t('Add Purchase Order') }}</Button>
        </template>
        <template #actions="{ row }">
            <RowMenu>
                <DropdownItem v-if="can('Purchase Order View')" @select="viewing = row"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="can('Purchase Order Info') && !row.deleted" @select="showInfo(row)"><Info />{{ t('Info') }}</DropdownItem>
                <DropdownItem v-if="row.editable && can('Purchase Order Update')" :href="route('accounting.purchase-orders.edit', row.id)"><Pencil />{{ t('Edit') }}</DropdownItem>
                <DropdownItem v-if="can('Purchase Order View') && !row.deleted" external :href="route('accounting.purchase-orders.pdf', row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                <template v-if="row.can_delete && can('Purchase Order Delete')">
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
            { label: t('Order Date'), value: viewing.order_date },
            { label: t('Status'), value: viewing.status?.label },
            { label: t('Supplier'), value: viewing.client?.name },
            { label: t('Warehouse'), value: viewing.warehouse },
            { label: t('Delivery Type'), value: viewing.delivery_type },
            { label: t('Total Amount'), value: viewing.total_amount },
            { label: t('Notes'), value: viewing.notes },
        ]"
    />
    <RecordInfoModal v-if="informing" v-model:open="infoOpen" :title="informing.reference_no" :url="route('accounting.purchase-orders.info', informing.id)" />
</template>
