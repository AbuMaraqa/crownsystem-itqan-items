<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import type { ViewField } from '@/Components/table/RecordViewModal.vue';
import Badge from '@/Components/ui/Badge.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { X } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import type { StatusBadge } from '../parts/statusBadge';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    reference_no: string;
    fiscal_year: string | null;
    closing_date: string | null;
    net_profit: string;
    is_profit: boolean;
    journal_entry: { id: number; entry_number: string } | null;
    status: StatusBadge | null;
    is_posted: boolean;
}

const props = defineProps<{ table: TablePayload<Row>; statuses: Option[]; fiscalYears: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference'), sort: 'reference_no' },
    { key: 'fiscal_year', label: t('Fiscal Year') },
    { key: 'closing_date', label: t('Closing Date'), sort: 'closing_date' },
    { key: 'net_profit', label: t('Net Profit / Loss'), sort: 'net_profit_base', class: 'text-end' },
    { key: 'journal_entry', label: t('Journal Entry') },
    { key: 'status', label: t('Status') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'status', label: t('Status'), options: props.statuses },
    { key: 'fiscal_year', label: t('Fiscal Year'), options: props.fiscalYears },
    { type: 'date-range', key: 'closing_date', label: t('Closing Date') },
]);

async function cancel(row: Row): Promise<void> {
    if (await confirm({ title: `${t('Cancel')} - ${row.reference_no}`, description: t('The closing entry will be reversed. The fiscal year must be open.'), confirmLabel: t('Cancel') })) {
        router.post(route('accounting.period-closing-vouchers.cancel', row.id), {}, { preserveScroll: true });
    }
}

function viewFields(row: Row): ViewField[] {
    return [
        { label: t('Reference'), value: row.reference_no },
        { label: t('Fiscal Year'), value: row.fiscal_year },
        { label: t('Closing Date'), value: row.closing_date },
        { label: t('Net Profit / Loss'), value: row.net_profit },
        { label: t('Journal Entry'), value: row.journal_entry?.entry_number },
        { label: t('Status'), value: row.status?.label },
    ];
}
</script>

<template>
    <PageHeader :title="t('Period Closing Vouchers')" :count="table.meta.total" />

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No closing vouchers found')">
        <template #cell-reference_no="{ row }">
            <bdi dir="ltr">{{ row.reference_no }}</bdi>
        </template>
        <template #cell-net_profit="{ row }">
            <span class="font-medium tabular-nums" :class="row.is_profit ? 'text-success-700 dark:text-success-400' : 'text-error-700 dark:text-error-400'" dir="ltr">{{ row.net_profit }}</span>
        </template>
        <template #cell-journal_entry="{ row }">
            <template v-if="row.journal_entry">
                <Link v-if="can('Journal Entry View')" :href="route('accounting.journal-entries.show', row.journal_entry.id)" class="text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ row.journal_entry.entry_number }}</Link>
                <bdi v-else dir="ltr">{{ row.journal_entry.entry_number }}</bdi>
            </template>
            <span v-else>-</span>
        </template>
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone">{{ row.status.label }}</Badge>
        </template>
        <template #actions="{ row }">
            <!-- A closing voucher is neither edited nor deleted: it is cancelled (its entry reversed). -->
            <RecordActions
                entity="Period Closing Voucher"
                routes="accounting.period-closing-vouchers"
                :id="row.id"
                :title="row.reference_no"
                :fields="viewFields(row)"
                :editable="false"
                :deletable="false"
            >
                <template v-if="row.is_posted && can('Period Closing Voucher Cancel')" #default>
                    <DropdownItem destructive @select="cancel(row)"><X />{{ t('Cancel') }}</DropdownItem>
                </template>
            </RecordActions>
        </template>
    </DataTable>
</template>
