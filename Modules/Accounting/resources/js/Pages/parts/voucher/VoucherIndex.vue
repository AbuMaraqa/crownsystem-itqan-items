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
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Eye, Info, Pencil, Printer, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordInfoModal from '@modules/Items/resources/js/Pages/parts/RecordInfoModal.vue';
import RecordViewModal from '@modules/Items/resources/js/Pages/parts/RecordViewModal.vue';

import type { Option, UserCellData } from '../types';
import type { StatusBadgeData, VoucherKind } from './types';

export interface VoucherRow {
    id: number;
    reference_no: string;
    voucher_date: string | null;
    client: UserCellData | null;
    account: string | null;
    payment_method: string | null;
    currency: string | null;
    amount: string;
    amount_full: string;
    status: StatusBadgeData | null;
    cheque_number: string | null;
    notes: string | null;
    editable: boolean;
    deleted: boolean;
}

const props = defineProps<{
    kind: VoucherKind;
    table: TablePayload<VoucherRow>;
    statusOptions: Option[];
    currencyOptions: Option[];
    paymentMethods: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference No'), sort: 'reference_no' },
    { key: 'voucher_date', label: t('Voucher Date'), sort: 'voucher_date' },
    { key: 'client', label: t('Client') },
    { key: 'account', label: t(props.kind.account) },
    { key: 'payment_method', label: t('Payment Method') },
    { key: 'currency', label: t('Currency') },
    { key: 'amount', label: t('Amount'), sort: 'amount', class: 'tabular' },
    { key: 'status', label: t('Status'), sort: 'status' },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'reference_no', label: t('Reference No'), placeholder: t('Search by Reference No') },
    { type: 'text', key: 'client', label: t('Client') },
    { type: 'text', key: 'notes', label: t('Notes'), placeholder: t('Search by notes') },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { key: 'payment_method', label: t('Payment Method'), options: props.paymentMethods },
    { key: 'currency', label: t('Currency'), options: props.currencyOptions },
    { type: 'date-range', key: 'voucher_date', label: t('Voucher Date') },
    { type: 'number-range', key: 'amount', label: t('Amount') },
    {
        key: 'trashed',
        label: t('Deleted records'),
        options: [
            { value: 'with', label: t('With deleted records') },
            { value: 'only', label: t('Only deleted records') },
        ],
    },
]);

const can_ = (action: string): boolean => can(`${props.kind.entity} ${action}`);
const canEdit = (row: VoucherRow): boolean => !row.deleted && can_('Update');

const viewing = ref<VoucherRow | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});

const informing = ref<VoucherRow | null>(null);
const infoOpen = ref(false);

/** The info window loads when it opens: mount it closed, then open it. */
function showInfo(row: VoucherRow): void {
    informing.value = row;
    infoOpen.value = false;
    nextTick(() => (infoOpen.value = true));
}

async function remove(row: VoucherRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route(`${props.kind.routes}.destroy`, row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t(kind.list)" :count="table.meta.total">
        <template #actions>
            <Button v-if="can_('Create')" variant="primary" :href="route(`${kind.routes}.add`)">{{ t(kind.add) }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t(kind.empty)">
        <template #cell-reference_no="{ row }">
            <Link v-if="canEdit(row)" :href="route(`${kind.routes}.edit`, row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.reference_no }}</Link>
            <span v-else dir="ltr" :class="{ 'text-muted line-through': row.deleted }">{{ row.reference_no }}</span>
        </template>
        <template #cell-client="{ row }">
            <UserCell v-if="row.client" :name="row.client.name" :avatar="row.client.avatar" :detail="row.client.detail" />
            <span v-else class="text-muted">-</span>
        </template>
        <template #cell-account="{ row }">{{ row.account ?? '-' }}</template>
        <template #cell-payment_method="{ row }">
            <Badge v-if="row.payment_method">{{ row.payment_method }}</Badge>
        </template>
        <template #cell-currency="{ row }">
            <span dir="ltr">{{ row.currency ?? '-' }}</span>
        </template>
        <template #cell-amount="{ row }">
            <span dir="ltr" class="tabular">{{ row.amount }}</span>
        </template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone" dot>{{ row.status.label }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can_('Create')" variant="primary" :href="route(`${kind.routes}.add`)">{{ t(kind.add) }}</Button>
        </template>
        <template #actions="{ row }">
            <RowMenu v-if="can_('View') || can_('Info') || canEdit(row)">
                <DropdownItem v-if="can_('View')" @select="viewing = row"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="can_('Info') && !row.deleted" @select="showInfo(row)"><Info />{{ t('Info') }}</DropdownItem>
                <DropdownItem v-if="canEdit(row)" :href="route(`${kind.routes}.edit`, row.id)"><Pencil />{{ row.editable ? t('Edit') : t('Open') }}</DropdownItem>
                <DropdownItem v-if="can_('View') && !row.deleted" external :href="route(`${kind.routes}.pdf`, row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                <template v-if="row.editable && can_('Delete')">
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
            { label: t('Voucher Date'), value: viewing.voucher_date },
            { label: t('Status'), value: viewing.status?.label },
            { label: t('Client'), value: viewing.client?.name },
            { label: t(kind.account), value: viewing.account },
            { label: t('Currency'), value: viewing.currency },
            { label: t('Payment Method'), value: viewing.payment_method },
            { label: t('Amount'), value: viewing.amount_full },
            { label: t('Cheque Number'), value: viewing.cheque_number },
            { label: t('Notes'), value: viewing.notes },
        ]"
    />
    <RecordInfoModal v-if="informing" v-model:open="infoOpen" :title="informing.reference_no" :url="route(`${kind.routes}.info`, informing.id)" />
</template>
