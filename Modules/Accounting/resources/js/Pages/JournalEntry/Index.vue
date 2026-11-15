<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import DropdownSeparator from '@/Components/ui/DropdownSeparator.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { CircleCheck, Eye, Info, Pencil, Trash2, X } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordInfoModal from '@modules/Items/resources/js/Pages/parts/RecordInfoModal.vue';
import RecordViewModal from '@modules/Items/resources/js/Pages/parts/RecordViewModal.vue';

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
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'status', label: t('Status'), options: props.statuses },
    { type: 'text', key: 'reference_no', label: t('Reference No') },
    { type: 'date-range', key: 'entry_date', label: t('Entry Date') },
]);

const { post, voidEntry, remove } = useJournalEntryActions();

// One info and one view window for the table, filled by the row that opened it.
const current = ref<Row | null>(null);
const infoOpen = ref(false);
const viewOpen = ref(false);

async function open(row: Row, window: 'info' | 'view'): Promise<void> {
    current.value = row;
    // The info window is rebuilt for the new row first, so it loads that row when it opens.
    await nextTick();
    (window === 'info' ? infoOpen : viewOpen).value = true;
}

const hasMenu = (row: Row): boolean =>
    can('Journal Entry View') ||
    can('Journal Entry Info') ||
    (row.is_draft && ['Post', 'Update', 'Delete'].some((action) => can(`Journal Entry ${action}`))) ||
    (row.can_void && can('Journal Entry Void'));
</script>

<template>
    <PageHeader :title="t('Journal Entries')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Journal Entry Create')" variant="primary" :href="route('accounting.journal-entries.add')">{{ t('Add Journal Entry') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No journal entries found')">
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
            <RowMenu v-if="hasMenu(row)">
                <DropdownItem v-if="row.is_draft && can('Journal Entry Post')" @select="post(row.id, row.entry_number)"><CircleCheck />{{ t('Post') }}</DropdownItem>
                <DropdownItem v-if="row.can_void && can('Journal Entry Void')" @select="voidEntry(row.id, row.entry_number)"><X />{{ t('Void') }}</DropdownItem>
                <DropdownItem v-if="can('Journal Entry View')" @select="open(row, 'view')"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="can('Journal Entry Info')" @select="open(row, 'info')"><Info />{{ t('Info') }}</DropdownItem>
                <DropdownItem v-if="row.is_draft && can('Journal Entry Update')" :href="route('accounting.journal-entries.edit', row.id)"><Pencil />{{ t('Edit') }}</DropdownItem>
                <template v-if="row.is_draft && can('Journal Entry Delete')">
                    <DropdownSeparator />
                    <DropdownItem destructive @select="remove(row.id, row.entry_number)"><Trash2 />{{ t('Delete') }}</DropdownItem>
                </template>
            </RowMenu>
        </template>
    </DataTable>

    <template v-if="current">
        <RecordInfoModal
            v-if="can('Journal Entry Info')"
            :key="`info-${current.id}`"
            v-model:open="infoOpen"
            :title="current.entry_number"
            :url="route('accounting.journal-entries.info', current.id)"
        />
        <RecordViewModal
            v-if="can('Journal Entry View')"
            v-model:open="viewOpen"
            :title="current.entry_number"
            :fields="[
                { label: t('Entry Number'), value: current.entry_number },
                { label: t('Status'), value: current.status?.label },
                { label: t('Entry Date'), value: current.entry_date },
                { label: t('Reference No'), value: current.reference_no },
                { label: t('Debit Total'), value: current.debit_total },
                { label: t('Credit Total'), value: current.credit_total },
            ]"
        />
    </template>
</template>
