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

interface ReturnRow {
    id: number;
    reference_no: string;
    return_date: string | null;
    purchase_invoice: string | null;
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
    userOptions: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'return_date', label: t('Return Date'), sort: 'return_date' },
    { key: 'purchase_invoice', label: t('Purchase Invoice') },
    { key: 'client', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse'), toggleable: true },
    { key: 'currency', label: t('Currency'), toggleable: true },
    { key: 'total_amount', label: t('Total Amount'), sort: 'total_amount', class: 'tabular' },
    { key: 'status', label: t('Status'), sort: 'status' },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'purchase_invoice', label: t('Purchase Invoice') },
    { type: 'text', key: 'client', label: t('Supplier') },
    { key: 'warehouse', label: t('Warehouse'), options: props.warehouseOptions },
    { type: 'text', key: 'notes', label: t('Notes'), placeholder: t('Search by notes') },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    { type: 'date-range', key: 'return_date', label: t('Return Date') },
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

/** The return page needs the update permission (its route), as before. */
const canOpen = (row: ReturnRow): boolean => !row.deleted && can('Purchase Return Update');

const viewing = ref<ReturnRow | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});

const informing = ref<ReturnRow | null>(null);
const infoOpen = ref(false);

function showInfo(row: ReturnRow): void {
    informing.value = row;
    infoOpen.value = false;
    nextTick(() => (infoOpen.value = true));
}

async function remove(row: ReturnRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.purchase-returns.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Purchase Returns')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Purchase Return Create')" variant="primary" :href="route('accounting.purchase-returns.add')">{{ t('Add Purchase Return') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No purchase returns found')">
        <template #cell-reference_no="{ row }">
            <Link v-if="canOpen(row)" :href="route('accounting.purchase-returns.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" :class="{ 'text-muted line-through': row.deleted }">{{ row.reference_no }}</span>
        </template>
        <template #cell-return_date="{ row }"><span dir="ltr">{{ row.return_date ?? '-' }}</span></template>
        <template #cell-purchase_invoice="{ row }"><bdi>{{ row.purchase_invoice ?? '-' }}</bdi></template>
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
            <Button v-if="can('Purchase Return Create')" variant="primary" :href="route('accounting.purchase-returns.add')">{{ t('Add Purchase Return') }}</Button>
        </template>
        <template #actions="{ row }">
            <RowMenu>
                <DropdownItem v-if="can('Purchase Return View')" @select="viewing = row"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="can('Purchase Return Info') && !row.deleted" @select="showInfo(row)"><Info />{{ t('Info') }}</DropdownItem>
                <DropdownItem v-if="canOpen(row)" :href="route('accounting.purchase-returns.edit', row.id)"><Pencil />{{ row.editable ? t('Edit') : t('Open') }}</DropdownItem>
                <DropdownItem v-if="can('Purchase Return View') && !row.deleted" external :href="route('accounting.purchase-returns.pdf', row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                <template v-if="row.editable && can('Purchase Return Delete')">
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
            { label: t('Return Date'), value: viewing.return_date },
            { label: t('Status'), value: viewing.status?.label },
            { label: t('Purchase Invoice'), value: viewing.purchase_invoice },
            { label: t('Supplier'), value: viewing.client?.name },
            { label: t('Warehouse'), value: viewing.warehouse },
            { label: t('Currency'), value: viewing.currency },
            { label: t('Exchange Rate'), value: viewing.exchange_rate },
            { label: t('Tax'), value: viewing.tax },
            { label: t('Tax Rate'), value: viewing.tax_rate },
            { label: t('Total Amount'), value: viewing.total_amount_full },
            { label: t('Notes'), value: viewing.notes },
        ]"
    />
    <RecordInfoModal v-if="informing" v-model:open="infoOpen" :title="informing.reference_no" :url="route('accounting.purchase-returns.info', informing.id)" />
</template>
