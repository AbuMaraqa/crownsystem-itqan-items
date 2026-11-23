<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { Boxes, MapPin } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@/Components/table/RecordActions.vue';
import { useRecordDelete } from '@/lib/useRecordDelete';

import type { Option } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    code: string | null;
    branch: string | null;
    inventory_account: { code: string; name: string } | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    description: string | null;
    is_active: boolean;
    locales: string[];
    stock_url: string;
}

const props = defineProps<{ table: TablePayload<Row>; branchOptions: Option[]; locales: string[] }>();

const routes = 'accounting.warehouses';

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'code', label: t('Code'), sort: 'code' },
    { key: 'branch', label: t('Branch') },
    { key: 'inventory_account', label: t('Inventory Account'), toggleable: true },
    { key: 'phone', label: t('Phone') },
    { key: 'email', label: t('Email') },
    { key: 'is_active', label: t('Status'), sort: 'is_active' },
    { key: 'locales', label: t('Locale'), toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search') },
    { type: 'text', key: 'code', label: t('Code') },
    { key: 'branch', label: t('Branch'), options: props.branchOptions },
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { key: 'locale', label: t('Locale'), options: props.locales.map((locale) => ({ value: locale, label: locale.toUpperCase() })) },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { remove } = useRecordDelete(routes);
</script>

<template>
    <PageHeader :title="t('Warehouses')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Warehouse Create')" variant="primary" :href="route('accounting.warehouses.add')">{{ t('Add Warehouse') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Warehouse Delete') ? route('accounting.warehouses.destroy-many') : undefined" :reorder-url="can('Warehouse Update') ? route('accounting.warehouses.reorder') : undefined" :empty-title="t('No warehouses found')" :empty-text="t('Create a new warehouse by clicking the button below')">
        <template #cell-name="{ row }">
            <Link v-if="can('Warehouse Update')" :href="route('accounting.warehouses.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ row.name }}</Link>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-code="{ row }">
            <span dir="ltr">{{ row.code ?? '-' }}</span>
        </template>
        <template #cell-branch="{ row }">{{ row.branch ?? '-' }}</template>
        <template #cell-inventory_account="{ row }">
            <span v-if="row.inventory_account" class="block">
                <span class="tabular block" dir="ltr">{{ row.inventory_account.code }}</span>
                <span class="block text-xs text-muted">{{ row.inventory_account.name }}</span>
            </span>
            <span v-else class="text-muted">{{ t('Created on first posting') }}</span>
        </template>
        <template #cell-phone="{ row }">
            <span dir="ltr">{{ row.phone ?? '-' }}</span>
        </template>
        <template #cell-email="{ row }">
            <span dir="ltr">{{ row.email ?? '-' }}</span>
        </template>
        <template #cell-is_active="{ row }">
            <Badge :tone="row.is_active ? 'success' : 'neutral'" dot>{{ row.is_active ? t('Active') : t('Inactive') }}</Badge>
        </template>
        <template #cell-locales="{ row }">
            <span class="text-muted uppercase">{{ row.locales.join(', ') }}</span>
        </template>
        <template #empty>
            <Button v-if="can('Warehouse Create')" variant="primary" :href="route('accounting.warehouses.add')">{{ t('Add Warehouse') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Warehouse"
                :routes="routes"
                :id="row.id"
                :title="row.name"
                :edit-href="route('accounting.warehouses.edit', row.id)"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Code'), value: row.code },
                    { label: t('Branch'), value: row.branch },
                    { label: t('Phone'), value: row.phone },
                    { label: t('Email'), value: row.email },
                    { label: t('Status'), value: row.is_active ? t('Active') : t('Inactive') },
                    { label: t('Address'), value: row.address },
                    { label: t('Description'), value: row.description },
                ]"
                @delete="remove(row.id, row.name)"
            >
                <template v-if="can('Warehouse Location View List') || can('Stock View List')" #default>
                    <DropdownItem v-if="can('Warehouse Location View List')" :href="route('accounting.inventory.layout', row.id)"><MapPin />{{ t('Warehouse Layout') }}</DropdownItem>
                    <DropdownItem v-if="can('Stock View List')" :href="row.stock_url"><Boxes />{{ t('Stock Balances') }}</DropdownItem>
                </template>
            </RecordActions>
        </template>
    </DataTable>
</template>
