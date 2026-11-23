<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
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
    code: string | null;
    country: string | null;
    locales: string[];
}

const props = defineProps<{ table: TablePayload<Row>; codes: string[]; countries: Option[]; locales: string[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'code', label: t('Code'), sort: 'code' },
    { key: 'country', label: t('Country') },
    { key: 'locales', label: t('Locale'), toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name') },
    { key: 'code', label: t('Code'), options: props.codes.map((code) => ({ value: code, label: code })) },
    { key: 'country_id', label: t('Country'), options: props.countries },
    { key: 'locale', label: t('Locale'), options: props.locales.map((locale) => ({ value: locale, label: locale.toUpperCase() })) },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { remove } = useRecordDelete('governorates');
</script>

<template>
    <PageHeader :title="t('Governorates List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Governorate Create')" variant="primary" :href="route('governorates.add')">{{ t('Add Governorate') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Governorate Delete') ? route('governorates.destroy-many') : undefined" :reorder-url="can('Governorate Update') ? route('governorates.reorder') : undefined" :empty-title="t('No governorates found')" :empty-text="t('Create a new governorate by clicking the button below')">
        <template #cell-name="{ row }">
            <Link v-if="can('Governorate Update')" :href="route('governorates.edit', row.id)" class="font-medium text-brand-600 dark:text-brand-400 hover:underline">{{ row.name }}</Link>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-locales="{ row }">
            <span class="text-muted uppercase">{{ row.locales.join(', ') }}</span>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Governorate"
                routes="governorates"
                :id="row.id"
                :title="row.name"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Code'), value: row.code },
                    { label: t('Country'), value: row.country },
                ]"
                :edit-href="route('governorates.edit', row.id)"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>
</template>
