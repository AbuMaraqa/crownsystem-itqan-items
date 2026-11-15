<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import DropdownSeparator from '@/Components/ui/DropdownSeparator.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { ArrowUpDown, Eye, Pencil, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordViewModal from '@modules/Items/resources/js/Pages/parts/RecordViewModal.vue';

import type { StatusBadge } from '../parts/statusBadge';
import type { Option, UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    reference_no: string;
    order_date: string | null;
    expected_delivery_date: string | null;
    client: UserCellData | null;
    warehouse: string | null;
    currency: string | null;
    total_amount: string;
    total_amount_full: string;
    status: StatusBadge | null;
    status_value: number | null;
    notes: string | null;
    deleted: boolean;
}

const props = defineProps<{ table: TablePayload<Row>; statusOptions: Option[]; warehouseOptions: Option[]; currencyOptions: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'order_date', label: t('Order Date'), sort: 'order_date' },
    { key: 'expected_delivery_date', label: t('Expected Delivery Date'), sort: 'expected_delivery_date', toggleable: true },
    { key: 'client', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse'), toggleable: true },
    { key: 'currency', label: t('Currency'), toggleable: true },
    { key: 'total_amount', label: t('Total Amount'), sort: 'total_amount', class: 'tabular' },
    { key: 'status', label: t('Status'), sort: 'status' },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'client', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse'), options: props.warehouseOptions },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    { type: 'date-range', key: 'order_date', label: t('Order Date') },
    { type: 'date-range', key: 'expected_delivery_date', label: t('Expected Delivery Date') },
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

const canEdit = (row: Row): boolean => !row.deleted && can('Consolidated Purchase Order Update');

const viewing = ref<Row | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});

const statusRow = ref<Row | null>(null);
const statusOpen = ref(false);
const statusForm = useForm<{ status: number | null }>({ status: null });

function changeStatus(row: Row): void {
    statusRow.value = row;
    statusForm.status = row.status_value;
    statusForm.clearErrors();
    statusOpen.value = true;
}

function saveStatus(): void {
    if (!statusRow.value) return;

    statusForm.post(route('accounting.consolidated-purchase-orders.status', statusRow.value.id), { preserveScroll: true, onSuccess: () => (statusOpen.value = false) });
}

async function remove(row: Row): Promise<void> {
    if (await confirm({ title: t('Are you sure you want to delete this consolidated purchase order?'), description: row.reference_no })) {
        router.delete(route('accounting.consolidated-purchase-orders.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Consolidated Purchase Orders')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Consolidated Purchase Order Create')" variant="primary" :href="route('accounting.consolidated-purchase-orders.add')">{{ t('Add Consolidated Purchase Order') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No consolidated purchase orders found')">
        <template #cell-reference_no="{ row }">
            <Link v-if="canEdit(row)" :href="route('accounting.consolidated-purchase-orders.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" :class="{ 'text-muted line-through': row.deleted }">{{ row.reference_no }}</span>
        </template>
        <template #cell-order_date="{ row }"><span dir="ltr">{{ row.order_date ?? '-' }}</span></template>
        <template #cell-expected_delivery_date="{ row }"><span dir="ltr">{{ row.expected_delivery_date ?? '-' }}</span></template>
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
            <Button v-if="can('Consolidated Purchase Order Create')" variant="primary" :href="route('accounting.consolidated-purchase-orders.add')">{{ t('Add Consolidated Purchase Order') }}</Button>
        </template>
        <template #actions="{ row }">
            <RowMenu v-if="can('Consolidated Purchase Order View') || can('Consolidated Purchase Order Update') || can('Consolidated Purchase Order Delete')">
                <DropdownItem v-if="can('Consolidated Purchase Order View')" @select="viewing = row"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="canEdit(row)" :href="route('accounting.consolidated-purchase-orders.edit', row.id)"><Pencil />{{ t('Edit') }}</DropdownItem>
                <!-- A deleted order is "Deleted / Cancelled" and its status does not change. -->
                <DropdownItem v-if="canEdit(row)" @select="changeStatus(row)"><ArrowUpDown />{{ t('Change Status') }}</DropdownItem>
                <template v-if="!row.deleted && can('Consolidated Purchase Order Delete')">
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
            { label: t('Expected Delivery Date'), value: viewing.expected_delivery_date },
            { label: t('Supplier'), value: viewing.client?.name },
            { label: t('Warehouse'), value: viewing.warehouse },
            { label: t('Currency'), value: viewing.currency },
            { label: t('Total Amount'), value: viewing.total_amount_full },
            { label: t('Status'), value: viewing.status?.label },
            { label: t('Notes'), value: viewing.notes },
        ]"
    />

    <Modal v-model:open="statusOpen" :title="t('Change Status')" :description="statusRow?.reference_no" width="max-w-md">
        <form class="grid gap-4" @submit.prevent="saveStatus">
            <SelectInput v-model="statusForm.status" :label="t('Status')" required :options="statusOptions" :error="statusForm.errors.status" />
        </form>
        <template #footer>
            <Button @click="statusOpen = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="statusForm.processing" @click="saveStatus">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
