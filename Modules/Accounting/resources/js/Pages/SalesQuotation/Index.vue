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
import { Eye, Info, Pencil, Printer, ShoppingCart, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordInfoModal from '@modules/Items/resources/js/Pages/parts/RecordInfoModal.vue';
import RecordViewModal from '@modules/Items/resources/js/Pages/parts/RecordViewModal.vue';

import type { StatusBadge } from '../parts/statusBadge';
import type { Option, UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface QuotationRow {
    id: number;
    reference_no: string;
    quotation_date: string | null;
    valid_until: string | null;
    client: UserCellData | null;
    warehouse: string | null;
    currency: string | null;
    total_amount: string;
    total_amount_full: string;
    status: StatusBadge | null;
    notes: string | null;
    deleted: boolean;
    editable: boolean;
    can_convert: boolean;
    can_delete: boolean;
}

const props = defineProps<{
    table: TablePayload<QuotationRow>;
    statusOptions: Option[];
    currencyOptions: Option[];
    warehouseOptions: Option[];
    userOptions: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'quotation_date', label: t('Quotation Date'), sort: 'quotation_date' },
    { key: 'valid_until', label: t('Valid Until'), sort: 'valid_until', toggleable: true },
    { key: 'client', label: t('Client') },
    { key: 'warehouse', label: t('Warehouse'), toggleable: true },
    { key: 'currency', label: t('Currency'), toggleable: true },
    { key: 'total_amount', label: t('Total Amount'), sort: 'total_amount', class: 'tabular' },
    { key: 'status', label: t('Status'), sort: 'status' },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'client', label: t('Client') },
    { key: 'warehouse', label: t('Warehouse'), options: props.warehouseOptions },
    { type: 'text', key: 'notes', label: t('Notes'), placeholder: t('Search by notes') },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    { type: 'text', key: 'item_barcode', label: t('Barcode'), placeholder: t('Search by barcode') },
    { type: 'text', key: 'item_description', label: t('Description'), placeholder: t('Search by item description') },
    { type: 'date-range', key: 'quotation_date', label: t('Quotation Date') },
    { type: 'date-range', key: 'valid_until', label: t('Valid Until') },
    {
        key: 'validity',
        label: t('Validity State'),
        options: [
            { value: 'valid', label: t('Valid') },
            { value: 'expired', label: t('Expired') },
            { value: 'no_date', label: t('No Date') },
        ],
    },
    { type: 'number-range', key: 'sub_total', label: t('Subtotal') },
    { type: 'number-range', key: 'quotation_discount', label: t('Quotation Discount') },
    { type: 'number-range', key: 'total_amount', label: t('Total Amount') },
    {
        key: 'document_state',
        label: t('Document State'),
        options: [
            { value: 'with_items', label: t('With Items') },
            { value: 'without_items', label: t('Without Items') },
            { value: 'with_order', label: t('With Sales Order') },
            { value: 'without_order', label: t('Without Sales Order') },
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

const canEdit = (row: QuotationRow): boolean => !row.deleted && can('Sales Quotation Update');

const viewing = ref<QuotationRow | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});

const informing = ref<QuotationRow | null>(null);
const infoOpen = ref(false);

/** The info window loads when it opens: mount it closed, then open it. */
function showInfo(row: QuotationRow): void {
    informing.value = row;
    infoOpen.value = false;
    nextTick(() => (infoOpen.value = true));
}

async function convert(row: QuotationRow): Promise<void> {
    if (await confirm({ title: t('Create a sales order from this quotation?'), confirmLabel: t('Create Sales Order'), tone: 'primary' })) {
        router.post(route('accounting.sales-quotations.convert', row.id));
    }
}

async function remove(row: QuotationRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.sales-quotations.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Sales Quotations')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Sales Quotation Create')" variant="primary" :href="route('accounting.sales-quotations.add')">{{ t('Add Sales Quotation') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No sales quotations found')">
        <template #cell-reference_no="{ row }">
            <Link v-if="canEdit(row)" :href="route('accounting.sales-quotations.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" :class="{ 'text-muted line-through': row.deleted }">{{ row.reference_no }}</span>
        </template>
        <template #cell-quotation_date="{ row }"><span dir="ltr">{{ row.quotation_date ?? '-' }}</span></template>
        <template #cell-valid_until="{ row }"><span dir="ltr">{{ row.valid_until ?? '-' }}</span></template>
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
            <Button v-if="can('Sales Quotation Create')" variant="primary" :href="route('accounting.sales-quotations.add')">{{ t('Add Sales Quotation') }}</Button>
        </template>
        <template #actions="{ row }">
            <RowMenu>
                <DropdownItem v-if="can('Sales Quotation View')" @select="viewing = row"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="can('Sales Quotation Info') && !row.deleted" @select="showInfo(row)"><Info />{{ t('Info') }}</DropdownItem>
                <DropdownItem v-if="canEdit(row)" :href="route('accounting.sales-quotations.edit', row.id)"><Pencil />{{ row.editable ? t('Edit') : t('Open') }}</DropdownItem>
                <DropdownItem v-if="can('Sales Quotation View') && !row.deleted" external :href="route('accounting.sales-quotations.pdf', row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                <DropdownItem v-if="row.can_convert && can('Sales Order Create')" @select="convert(row)"><ShoppingCart />{{ t('Create Sales Order') }}</DropdownItem>
                <template v-if="row.can_delete && can('Sales Quotation Delete')">
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
            { label: t('Quotation Date'), value: viewing.quotation_date },
            { label: t('Valid Until'), value: viewing.valid_until },
            { label: t('Status'), value: viewing.status?.label },
            { label: t('Client'), value: viewing.client?.name },
            { label: t('Warehouse'), value: viewing.warehouse },
            { label: t('Currency'), value: viewing.currency },
            { label: t('Total Amount'), value: viewing.total_amount_full },
            { label: t('Notes'), value: viewing.notes },
        ]"
    />
    <RecordInfoModal v-if="informing" v-model:open="infoOpen" :title="informing.reference_no" :url="route('accounting.sales-quotations.info', informing.id)" />
</template>
