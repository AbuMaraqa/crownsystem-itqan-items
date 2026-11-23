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
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { ExternalLink, Printer } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

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
    createdByColumn(),
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

function viewFields(row: VoucherRow): ViewField[] {
    return [
        { label: t('Reference No'), value: row.reference_no },
        { label: t('Voucher Date'), value: row.voucher_date },
        { label: t('Status'), value: row.status?.label },
        { label: t('Client'), value: row.client?.name },
        { label: t(props.kind.account), value: row.account },
        { label: t('Currency'), value: row.currency },
        { label: t('Payment Method'), value: row.payment_method },
        { label: t('Amount'), value: row.amount_full },
        { label: t('Cheque Number'), value: row.cheque_number },
        { label: t('Notes'), value: row.notes },
    ];
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

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can_('Delete') ? route(`${kind.routes}.destroy-many`) : undefined" :empty-title="t(kind.empty)">
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
            <RecordActions
                :entity="kind.entity"
                :routes="kind.routes"
                :id="row.id"
                :title="row.reference_no"
                :fields="viewFields(row)"
                :edit-href="route(`${kind.routes}.edit`, row.id)"
                :editable="row.editable"
                :deletable="row.editable"
                @delete="remove(row)"
            >
                <template v-if="!row.deleted" #default>
                    <DropdownItem v-if="!row.editable && canEdit(row)" :href="route(`${kind.routes}.edit`, row.id)"><ExternalLink />{{ t('Open document') }}</DropdownItem>
                    <DropdownItem v-if="can_('View')" external :href="route(`${kind.routes}.pdf`, row.id)"><Printer />{{ t('Print') }}</DropdownItem>
                </template>
            </RecordActions>
        </template>
    </DataTable>
</template>
