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
    city: string | null;
    governorate: string | null;
    latitude: string | null;
    longitude: string | null;
    type: string | null;
    locales: string[];
}

const props = defineProps<{
    table: TablePayload<Row>;
    countries: Option[];
    governorates: Option[];
    cities: Option[];
    types: Option[];
    locales: string[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'city', label: t('City') },
    { key: 'governorate', label: t('Governorate'), toggleable: true },
    { key: 'latitude', label: t('Latitude'), class: 'tabular', toggleable: true, hidden: true },
    { key: 'longitude', label: t('Longitude'), class: 'tabular', toggleable: true, hidden: true },
    { key: 'type', label: t('Type'), sort: 'type' },
    { key: 'locales', label: t('Locale'), toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name') },
    { key: 'country_id', label: t('Country'), options: props.countries },
    { key: 'governorate_id', label: t('Governorate'), options: props.governorates },
    { key: 'city_id', label: t('City'), options: props.cities },
    { key: 'type', label: t('Type'), options: props.types },
    { key: 'locale', label: t('Locale'), options: props.locales.map((locale) => ({ value: locale, label: locale.toUpperCase() })) },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { remove } = useRecordDelete('districts');
</script>

<template>
    <PageHeader :title="t('Districts List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('District Create')" variant="primary" :href="route('districts.add')">{{ t('Add District') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('District Delete') ? route('districts.destroy-many') : undefined" :reorder-url="can('District Update') ? route('districts.reorder') : undefined" :empty-title="t('No districts found')" :empty-text="t('Create a new district by clicking the button below')">
        <template #cell-name="{ row }">
            <Link v-if="can('District Update')" :href="route('districts.edit', row.id)" class="font-medium text-brand-600 dark:text-brand-400 hover:underline">{{ row.name }}</Link>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-locales="{ row }">
            <span class="text-muted uppercase">{{ row.locales.join(', ') }}</span>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="District"
                routes="districts"
                :id="row.id"
                :title="row.name"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('City'), value: row.city },
                    { label: t('Governorate'), value: row.governorate },
                    { label: t('Type'), value: row.type },
                    { label: t('Latitude'), value: row.latitude },
                    { label: t('Longitude'), value: row.longitude },
                ]"
                :edit-href="route('districts.edit', row.id)"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>
</template>
