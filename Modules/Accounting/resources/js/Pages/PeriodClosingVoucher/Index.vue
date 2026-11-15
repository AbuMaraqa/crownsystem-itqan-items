<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Badge from '@/Components/ui/Badge.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Info, X } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordInfoModal from '@modules/Items/resources/js/Pages/parts/RecordInfoModal.vue';

import type { StatusBadge } from '../parts/statusBadge';
import type { UserCellData } from '../parts/types';

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
    created_by: UserCellData | null;
}

const props = defineProps<{ table: TablePayload<Row>; statuses: Option[]; fiscalYears: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'reference_no', label: t('Reference'), sort: 'reference_no' },
    { key: 'fiscal_year', label: t('Fiscal Year') },
    { key: 'closing_date', label: t('Closing Date'), sort: 'closing_date' },
    { key: 'net_profit', label: t('Net Profit / Loss'), sort: 'net_profit_base', class: 'text-end' },
    { key: 'journal_entry', label: t('Journal Entry') },
    { key: 'status', label: t('Status') },
    { key: 'created_by', label: t('Created By'), toggleable: true },
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

// The info window of the closing vouchers is open to whoever sees the list, as before.
const current = ref<Row | null>(null);
const infoOpen = ref(false);

async function info(row: Row): Promise<void> {
    current.value = row;
    await nextTick();
    infoOpen.value = true;
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
        <template #cell-created_by="{ row }">
            <UserCell v-if="row.created_by" :name="row.created_by.name" :avatar="row.created_by.avatar" :detail="row.created_by.detail" />
            <span v-else>-</span>
        </template>
        <template #actions="{ row }">
            <RowMenu>
                <DropdownItem v-if="row.is_posted && can('Period Closing Voucher Cancel')" destructive @select="cancel(row)"><X />{{ t('Cancel') }}</DropdownItem>
                <DropdownItem @select="info(row)"><Info />{{ t('Info') }}</DropdownItem>
            </RowMenu>
        </template>
    </DataTable>

    <RecordInfoModal
        v-if="current"
        :key="current.id"
        v-model:open="infoOpen"
        :title="current.reference_no"
        :url="route('accounting.period-closing-vouchers.info', current.id)"
    />
</template>
