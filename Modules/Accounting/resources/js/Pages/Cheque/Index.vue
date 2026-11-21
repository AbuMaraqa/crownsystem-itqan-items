<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { CircleCheck, CircleAlert, X } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@/Components/table/RecordActions.vue';

import type { StatusBadge } from '../parts/statusBadge';
import type { UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    cheque_number: string;
    bank_name: string | null;
    direction: StatusBadge | null;
    cheque_date: string | null;
    client: UserCellData | null;
    account: string | null;
    clearing_account: string | null;
    currency: string | null;
    amount: string;
    status: StatusBadge | null;
    is_under_collection: boolean;
    notes: string | null;
}

const props = defineProps<{
    table: TablePayload<Row>;
    statuses: Option[];
    directions: Option[];
    currencies: Option[];
    bankAccounts: Option[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'cheque_number', label: t('Cheque Number'), sort: 'cheque_number' },
    { key: 'bank_name', label: t('Bank') },
    { key: 'direction', label: t('Direction') },
    { key: 'cheque_date', label: t('Due Date'), sort: 'cheque_date' },
    { key: 'client', label: t('Client') },
    { key: 'account', label: t('Account'), toggleable: true },
    { key: 'currency', label: t('Currency') },
    { key: 'amount', label: t('Amount'), sort: 'amount', class: 'text-end' },
    { key: 'status', label: t('Status') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'cheque_number', label: t('Cheque Number'), placeholder: t('Search by cheque number') },
    { type: 'text', key: 'client', label: t('Client'), placeholder: t('Search by name, phone or email') },
    { type: 'text', key: 'bank_name', label: t('Bank'), placeholder: t('Search by bank name') },
    { key: 'status', label: t('Status'), options: props.statuses },
    { key: 'direction', label: t('Direction'), options: props.directions },
    { key: 'currency', label: t('Currency'), options: props.currencies },
    { type: 'date-range', key: 'cheque_date', label: t('Due Date') },
    {
        key: 'trashed',
        label: t('Deleted records'),
        options: [
            { value: 'with', label: t('With deleted records') },
            { value: 'only', label: t('Only deleted records') },
        ],
    },
]);

const clearing = ref<Row | null>(null);
const clearOpen = ref(false);
const clearForm = useForm<{ clearing_account_id: number | string | null }>({ clearing_account_id: null });

function markCleared(row: Row): void {
    clearing.value = row;
    clearForm.reset();
    clearForm.clearErrors();
    clearOpen.value = true;
}

function submitClear(): void {
    if (!clearing.value) return;

    clearForm.post(route('accounting.cheques.clear', clearing.value.id), { preserveScroll: true, onSuccess: () => (clearOpen.value = false) });
}

async function transition(row: Row, action: 'bounce' | 'cancel'): Promise<void> {
    const label = action === 'bounce' ? t('Mark Bounced') : t('Cancel');

    if (await confirm({ title: `${label} - ${row.cheque_number}`, confirmLabel: label })) {
        router.post(route(`accounting.cheques.${action}`, row.id), {}, { preserveScroll: true });
    }
}

const fields = (row: Row) => [
    { label: t('Cheque Number'), value: row.cheque_number },
    { label: t('Bank'), value: row.bank_name },
    { label: t('Direction'), value: row.direction?.label },
    { label: t('Due Date'), value: row.cheque_date },
    { label: t('Client'), value: row.client?.name },
    { label: t('Account'), value: row.account },
    { label: t('Clearing Account'), value: row.clearing_account },
    { label: t('Amount'), value: row.currency ? `${row.amount} ${row.currency}` : row.amount },
    { label: t('Status'), value: row.status?.label },
    { label: t('Notes'), value: row.notes },
];
</script>

<template>
    <PageHeader :title="t('Cheques')" :count="table.meta.total" />

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No cheques found')">
        <template #cell-cheque_number="{ row }">
            <bdi class="font-medium" dir="ltr">{{ row.cheque_number }}</bdi>
        </template>
        <template #cell-bank_name="{ row }">{{ row.bank_name || '-' }}</template>
        <template #cell-direction="{ row }">
            <Badge v-if="row.direction" :tone="row.direction.tone">{{ row.direction.label }}</Badge>
        </template>
        <template #cell-client="{ row }">
            <UserCell v-if="row.client" :name="row.client.name" :avatar="row.client.avatar" :detail="row.client.detail" />
            <span v-else>-</span>
        </template>
        <template #cell-amount="{ row }">
            <span class="tabular-nums" dir="ltr">{{ row.amount }}</span>
        </template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone">{{ row.status.label }}</Badge>
        </template>
        <template #actions="{ row }">
            <RecordActions entity="Cheque" routes="accounting.cheques" :id="row.id" :title="row.cheque_number" :fields="fields(row)" :editable="false" :deletable="false">
                <template v-if="row.is_under_collection && (can('Cheque Clear') || can('Cheque Bounce') || can('Cheque Cancel'))" #default>
                    <DropdownItem v-if="can('Cheque Clear')" @select="markCleared(row)"><CircleCheck />{{ t('Mark Cleared') }}</DropdownItem>
                    <DropdownItem v-if="can('Cheque Bounce')" @select="transition(row, 'bounce')"><CircleAlert />{{ t('Mark Bounced') }}</DropdownItem>
                    <DropdownItem v-if="can('Cheque Cancel')" @select="transition(row, 'cancel')"><X />{{ t('Cancel') }}</DropdownItem>
                </template>
            </RecordActions>
        </template>
    </DataTable>

    <Modal v-model:open="clearOpen" :title="clearing ? `${t('Mark Cleared')} - ${clearing.cheque_number}` : t('Mark Cleared')" width="max-w-md">
        <form id="cheque-clear-form" @submit.prevent="submitClear">
            <MultiSelect v-model="clearForm.clearing_account_id" single :label="t('Bank Account')" required :options="bankAccounts" :error="clearForm.errors.clearing_account_id" />
        </form>
        <template #footer>
            <Button @click="clearOpen = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="clearForm.processing" @click="submitClear">{{ t('Mark Cleared') }}</Button>
        </template>
    </Modal>
</template>
