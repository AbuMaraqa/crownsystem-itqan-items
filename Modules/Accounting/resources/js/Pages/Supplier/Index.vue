<script setup lang="ts">
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    avatar: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    tax_number: string | null;
}

defineProps<{ table: TablePayload<Row> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'email', label: t('Email'), sort: 'email' },
    { key: 'phone', label: t('Phone') },
    { key: 'address', label: t('Address'), toggleable: true },
    { key: 'tax_number', label: t('Tax Number'), toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search') },
    { type: 'text', key: 'email', label: t('Email') },
    { type: 'text', key: 'phone', label: t('Phone') },
    { type: 'text', key: 'tax_number', label: t('Tax Number') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);
</script>

<template>
    <PageHeader :title="t('Suppliers')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Supplier Create')" variant="primary" :href="route('accounting.suppliers.add')">{{ t('Add Supplier') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No suppliers found')" :empty-text="t('Create a new supplier by clicking the button below')">
        <template #cell-name="{ row }">
            <UserCell :name="row.name" :avatar="row.avatar" :detail="row.phone" :href="can('Supplier Update') ? route('accounting.suppliers.edit', row.id) : null" />
        </template>
        <template #cell-email="{ row }">
            <span dir="ltr">{{ row.email ?? '-' }}</span>
        </template>
        <template #cell-phone="{ row }">
            <span dir="ltr">{{ row.phone ?? '-' }}</span>
        </template>
        <template #cell-address="{ row }">
            <span class="block max-w-64 truncate" :title="row.address ?? undefined">{{ row.address ?? '-' }}</span>
        </template>
        <template #cell-tax_number="{ row }">
            <span dir="ltr">{{ row.tax_number ?? '-' }}</span>
        </template>
        <template #empty>
            <Button v-if="can('Supplier Create')" variant="primary" :href="route('accounting.suppliers.add')">{{ t('Add Supplier') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Supplier"
                routes="accounting.suppliers"
                :id="row.id"
                :title="row.name"
                :edit-href="route('accounting.suppliers.edit', row.id)"
                :deletable="false"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Email'), value: row.email },
                    { label: t('Phone'), value: row.phone },
                    { label: t('Tax Number'), value: row.tax_number },
                    { label: t('Address'), value: row.address },
                ]"
            />
        </template>
    </DataTable>
</template>
