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
    code: string;
    phone_code: string | null;
    currency: string | null;
    locales: string[];
}

const props = defineProps<{
    table: TablePayload<Row>;
    codes: string[];
    phoneCodes: string[];
    currencies: Option[];
    locales: string[];
}>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'code', label: t('Code'), sort: 'code' },
    { key: 'phone_code', label: t('Phone Code'), sort: 'phone_code', class: 'tabular' },
    { key: 'currency', label: t('Currency') },
    { key: 'locales', label: t('Locale'), toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name') },
    { key: 'code', label: t('Code'), options: props.codes.map((code) => ({ value: code, label: code })) },
    { key: 'phone_code', label: t('Phone Code'), options: props.phoneCodes.map((code) => ({ value: code, label: code })) },
    { key: 'currency_id', label: t('Currency'), options: props.currencies },
    { key: 'locale', label: t('Locale'), options: props.locales.map((locale) => ({ value: locale, label: locale.toUpperCase() })) },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { remove } = useRecordDelete('countries');
</script>

<template>
    <PageHeader :title="t('Countries List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Country Create')" variant="primary" :href="route('countries.add')">{{ t('Add Country') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Country Delete') ? route('countries.destroy-many') : undefined" :reorder-url="can('Country Update') ? route('countries.reorder') : undefined" :empty-title="t('No countries found')" :empty-text="t('Create a new country by clicking the button below')">
        <template #cell-name="{ row }">
            <Link v-if="can('Country Update')" :href="route('countries.edit', row.id)" class="font-medium text-brand-600 dark:text-brand-400 hover:underline">{{ row.name }}</Link>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-locales="{ row }">
            <span class="text-muted uppercase">{{ row.locales.join(', ') }}</span>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Country"
                routes="countries"
                :id="row.id"
                :title="row.name"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Code'), value: row.code },
                    { label: t('Phone Code'), value: row.phone_code },
                    { label: t('Currency'), value: row.currency },
                ]"
                :edit-href="route('countries.edit', row.id)"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>
</template>
