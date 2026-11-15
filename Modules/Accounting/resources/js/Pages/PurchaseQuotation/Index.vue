<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { Eye, Info, Pencil, Printer } from '@/lib/icons';
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
    purchase_order: string | null;
    client: UserCellData | null;
    supplier_reference: string | null;
    valid_until: string | null;
    total_amount: string;
    total_amount_full: string;
    currency: string | null;
    status: StatusBadge | null;
    created_by: UserCellData | null;
    notes: string | null;
    deleted: boolean;
}

const props = defineProps<{
    table: TablePayload<QuotationRow>;
    statusOptions: Option[];
    currencyOptions: Option[];
    userOptions: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'quotation_date', label: t('Quotation Date'), sort: 'quotation_date' },
    { key: 'purchase_order', label: t('Purchase Order') },
    { key: 'client', label: t('Supplier') },
    { key: 'supplier_reference', label: t('Supplier Quotation No'), toggleable: true },
    { key: 'valid_until', label: t('Valid Until'), sort: 'valid_until', toggleable: true },
    { key: 'total_amount', label: t('Total Amount'), sort: 'total_amount', class: 'tabular' },
    { key: 'status', label: t('Status'), sort: 'status' },
    { key: 'created_by', label: t('Created By'), toggleable: true, hidden: true },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'purchase_order', label: t('Purchase Order') },
    { type: 'text', key: 'client', label: t('Supplier') },
    { type: 'text', key: 'supplier_reference', label: t('Supplier Quotation No') },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    { type: 'date-range', key: 'quotation_date', label: t('Quotation Date') },
    { type: 'date-range', key: 'valid_until', label: t('Valid Until') },
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

const canOpen = (row: QuotationRow): boolean => !row.deleted;

const viewing = ref<QuotationRow | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});

const informing = ref<QuotationRow | null>(null);
const infoOpen = ref(false);

function showInfo(row: QuotationRow): void {
    informing.value = row;
    infoOpen.value = false;
    nextTick(() => (infoOpen.value = true));
}
</script>

<template>
    <PageHeader :title="t('Purchase Quotations')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Purchase Quotation Create')" variant="primary" :href="route('accounting.purchase-quotations.add')">{{ t('Add Purchase Quotation') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No purchase quotations yet')">
        <template #cell-reference_no="{ row }">
            <Link v-if="canOpen(row)" :href="route('accounting.purchase-quotations.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" class="text-muted line-through">{{ row.reference_no }}</span>
        </template>
        <template #cell-quotation_date="{ row }"><span dir="ltr">{{ row.quotation_date ?? '-' }}</span></template>
        <template #cell-purchase_order="{ row }"><bdi>{{ row.purchase_order ?? '-' }}</bdi></template>
        <template #cell-client="{ row }">
            <UserCell v-if="row.client" :name="row.client.name" :avatar="row.client.avatar" :detail="row.client.detail" />
            <span v-else class="text-muted">-</span>
        </template>
        <template #cell-supplier_reference="{ row }">{{ row.supplier_reference ?? '-' }}</template>
        <template #cell-valid_until="{ row }"><span dir="ltr">{{ row.valid_until ?? '-' }}</span></template>
        <template #cell-total_amount="{ row }"><span dir="ltr" class="tabular">{{ row.total_amount }}</span></template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone" dot>{{ row.status.label }}</Badge>
        </template>
        <template #cell-created_by="{ row }">
            <UserCell v-if="row.created_by" :name="row.created_by.name" :avatar="row.created_by.avatar" :detail="row.created_by.detail" />
            <span v-else class="text-muted">-</span>
        </template>
        <template #empty>
            <Button v-if="can('Purchase Quotation Create')" variant="primary" :href="route('accounting.purchase-quotations.add')">{{ t('Add Purchase Quotation') }}</Button>
        </template>
        <template #actions="{ row }">
            <RowMenu>
                <DropdownItem @select="viewing = row"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="!row.deleted" @select="showInfo(row)"><Info />{{ t('Info') }}</DropdownItem>
                <DropdownItem v-if="canOpen(row)" :href="route('accounting.purchase-quotations.edit', row.id)"><Pencil />{{ t('Open') }}</DropdownItem>
                <DropdownItem v-if="!row.deleted" external :href="route('accounting.purchase-quotations.pdf', row.id)"><Printer />{{ t('Print') }}</DropdownItem>
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
            { label: t('Purchase Order'), value: viewing.purchase_order },
            { label: t('Supplier'), value: viewing.client?.name },
            { label: t('Supplier Quotation No'), value: viewing.supplier_reference },
            { label: t('Valid Until'), value: viewing.valid_until },
            { label: t('Status'), value: viewing.status?.label },
            { label: t('Total Amount'), value: `${viewing.total_amount_full} ${viewing.currency ?? ''}` },
            { label: t('Notes'), value: viewing.notes },
        ]"
    />
    <RecordInfoModal v-if="informing" v-model:open="infoOpen" :title="informing.reference_no" :url="route('accounting.purchase-quotations.info', informing.id)" />
</template>
