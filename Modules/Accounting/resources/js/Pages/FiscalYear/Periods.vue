<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import Badge from '@/Components/ui/Badge.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Returns, ShieldCheck } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import type { StatusBadge } from '../parts/statusBadge';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    starts_at: string | null;
    ends_at: string | null;
    status: StatusBadge | null;
    is_open: boolean;
}

const props = defineProps<{ fiscalYear: { id: number; name: string }; table: TablePayload<Row>; statuses: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'starts_at', label: t('Start Date'), sort: 'starts_at' },
    { key: 'ends_at', label: t('End Date'), sort: 'ends_at' },
    { key: 'status', label: t('Status') },
]);

const filters = computed<TableFilter[]>(() => [{ key: 'status', label: t('Status'), options: props.statuses }]);

async function close(row: Row): Promise<void> {
    if (await confirm({ title: `${t('Close')} - ${row.name}`, description: t('No entries can be posted with a date in this period after closing it.'), confirmLabel: t('Close') })) {
        router.post(route('accounting.fiscal-years.periods.close', row.id), {}, { preserveScroll: true });
    }
}

async function reopen(row: Row): Promise<void> {
    if (await confirm({ title: `${t('Reopen')} - ${row.name}`, tone: 'primary', confirmLabel: t('Reopen') })) {
        router.post(route('accounting.fiscal-years.periods.reopen', row.id), {}, { preserveScroll: true });
    }
}

const canAct = (row: Row): boolean => (row.is_open ? can('Accounting Period Close') : can('Accounting Period Reopen'));
</script>

<template>
    <PageHeader :title="`${t('Accounting Periods')} - ${fiscalYear.name}`" :count="table.meta.total" :parents="[{ title: t('Fiscal Years'), href: route('accounting.fiscal-years.index') }]" />

    <DataTable :table="table" :columns="columns" :filters="filters" :searchable="false">
        <template #cell-status="{ row }">
            <Badge v-if="row.status" :tone="row.status.tone">{{ row.status.label }}</Badge>
        </template>
        <template #actions="{ row }">
            <RowMenu v-if="canAct(row)">
                <DropdownItem v-if="row.is_open" @select="close(row)"><ShieldCheck />{{ t('Close') }}</DropdownItem>
                <DropdownItem v-else @select="reopen(row)"><Returns />{{ t('Reopen') }}</DropdownItem>
            </RowMenu>
        </template>
    </DataTable>
</template>
