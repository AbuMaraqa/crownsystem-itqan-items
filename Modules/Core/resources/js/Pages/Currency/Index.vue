<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import { useRecordDelete } from '@/lib/useRecordDelete';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    code: string;
    symbol: string;
    exchange_rate: string;
    is_active: boolean;
    locales: string[];
}

defineProps<{ table: TablePayload<Row> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name') },
    { key: 'code', label: t('Code'), sort: 'code' },
    { key: 'symbol', label: t('Symbol') },
    { key: 'exchange_rate', label: t('Exchange rate'), sort: 'exchange_rate', class: 'tabular' },
    { key: 'is_active', label: t('Status') },
    { key: 'locales', label: t('Locale'), toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { key: 'symbol_position', label: t('Position'), options: [{ value: 'before', label: t('Before') }, { value: 'after', label: t('After') }] },
    { type: 'number-range', key: 'exchange_rate', label: t('Exchange rate') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { remove, removeMany } = useRecordDelete('currencies');
</script>

<template>
    <PageHeader :title="t('Currencies List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Currency Create')" variant="primary" :href="route('currencies.add')">{{ t('Add Currency') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :selectable="can('Currency Delete')" :reorder-url="can('Currency Update') ? route('currencies.reorder') : undefined" :empty-title="t('No currencies found')" :empty-text="t('Add the currencies the system works with.')">
        <template #cell-name="{ row }">
            <Link v-if="can('Currency Update')" :href="route('currencies.edit', row.id)" class="font-medium text-brand-600 dark:text-brand-400 hover:underline">{{ row.name }}</Link>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-is_active="{ row }">
            <Badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('Active') : t('Inactive') }}</Badge>
        </template>
        <template #cell-locales="{ row }">
            <span class="text-muted uppercase">{{ row.locales.join(', ') }}</span>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Currency"
                routes="currencies"
                :id="row.id"
                :title="row.name"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Code'), value: row.code },
                    { label: t('Symbol'), value: row.symbol },
                    { label: t('Exchange rate'), value: row.exchange_rate },
                    { label: t('Status'), value: row.is_active ? t('Active') : t('Inactive') },
                ]"
                :edit-href="route('currencies.edit', row.id)"
                @delete="remove(row.id, row.name)"
            />
        </template>
        <template #bulk="{ selected }">
            <Button variant="danger" size="sm" @click="removeMany(selected)">{{ t('Delete') }}</Button>
        </template>
    </DataTable>
</template>
