<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import type { ViewField } from '@/Components/table/RecordViewModal.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { CircleCheck, ExternalLink, X } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import type { StatusBadge } from '../parts/statusBadge';
import { useJournalEntryActions } from './parts/useJournalEntryActions';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    entry_number: string;
    entry_date: string | null;
    status: StatusBadge | null;
    reference_no: string | null;
    debit_total: string;
    credit_total: string;
    is_draft: boolean;
    can_void: boolean;
}

const props = defineProps<{ table: TablePayload<Row>; statuses: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'entry_number', label: t('Entry Number'), sort: 'entry_number' },
    { key: 'entry_date', label: t('Entry Date'), sort: 'entry_date' },
    { key: 'status', label: t('Status') },
    { key: 'reference_no', label: t('Reference No') },
    { key: 'debit_total', label: t('Debit Total'), class: 'text-end' },
    { key: 'credit_total', label: t('Credit Total'), class: 'text-end' },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'status', label: t('Status'), options: props.statuses },
    { type: 'text', key: 'reference_no', label: t('Reference No') },
    { type: 'date-range', key: 'entry_date', label: t('Entry Date') },
]);

const { post, voidEntry, remove } = useJournalEntryActions();

function viewFields(row: Row): ViewField[] {
    return [
        { label: t('Entry Number'), value: row.entry_number },
        { label: t('Status'), value: row.status?.label },
        { label: t('Entry Date'), value: row.entry_date },
        { label: t('Reference No'), value: row.reference_no },
        { label: t('Debit Total'), value: row.debit_total },
        { label: t('Credit Total'), value: row.credit_total },
    ];
}

/** Post, Void and Open (the read-only page of an entry that can no longer be edited). */
const hasMore = (row: Row): boolean =>
    (row.is_draft && can('Journal Entry Post')) || (row.can_void && can('Journal Entry Void')) || (!(row.is_draft && can('Journal Entry Update')) && can('Journal Entry View'));
</script>

<template>
    <PageHeader :title="t('Journal Entries')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Journal Entry Create')" variant="primary" :href="route('accounting.journal-entries.add')">{{ t('Add Journal Entry') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Journal Entry Delete') ? route('accounting.journal-entries.destroy-many') : undefined" :empty-title="t('No journal entries found')">
        <template #cell-entry_number="{ row }">
            <Link v-if="can('Journal Entry View')" :href="route('accounting.journal-entries.show', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.entry_number }}</Link>
            <bdi v-else dir="ltr">{{ row.entry_number }}</bdi>
        </template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone">{{ row.status.label }}</Badge>
        </template>
        <template #cell-reference_no="{ row }">
            <bdi>{{ row.reference_no || '-' }}</bdi>
        </template>
        <template #cell-debit_total="{ row }">
            <span class="tabular-nums" dir="ltr">{{ row.debit_total }}</span>
        </template>
        <template #cell-credit_total="{ row }">
            <span class="tabular-nums" dir="ltr">{{ row.credit_total }}</span>
        </template>
        <template #empty>
            <Button v-if="can('Journal Entry Create')" variant="primary" :href="route('accounting.journal-entries.add')">{{ t('Add Journal Entry') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Journal Entry"
                routes="accounting.journal-entries"
                :id="row.id"
                :title="row.entry_number"
                :fields="viewFields(row)"
                :edit-href="route('accounting.journal-entries.edit', row.id)"
                :editable="row.is_draft"
                :deletable="row.is_draft"
                @delete="remove(row.id, row.entry_number)"
            >
                <template v-if="hasMore(row)" #default>
                    <DropdownItem v-if="!(row.is_draft && can('Journal Entry Update')) && can('Journal Entry View')" :href="route('accounting.journal-entries.show', row.id)"><ExternalLink />{{ t('Open document') }}</DropdownItem>
                    <DropdownItem v-if="row.is_draft && can('Journal Entry Post')" @select="post(row.id, row.entry_number)"><CircleCheck />{{ t('Post') }}</DropdownItem>
                    <DropdownItem v-if="row.can_void && can('Journal Entry Void')" @select="voidEntry(row.id, row.entry_number)"><X />{{ t('Void') }}</DropdownItem>
                </template>
            </RecordActions>
        </template>
    </DataTable>
</template>
