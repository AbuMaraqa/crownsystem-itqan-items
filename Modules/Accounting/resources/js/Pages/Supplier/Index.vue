<script setup lang="ts">
import { computed, ref } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { Eye, Pencil } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordViewModal from '@modules/Items/resources/js/Pages/parts/RecordViewModal.vue';

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
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search') },
    { type: 'text', key: 'email', label: t('Email') },
    { type: 'text', key: 'phone', label: t('Phone') },
    { type: 'text', key: 'tax_number', label: t('Tax Number') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const viewing = ref<Row | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});
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
            <RowMenu v-if="can('Supplier View') || can('Supplier Update')">
                <DropdownItem v-if="can('Supplier View')" @select="viewing = row"><Eye />{{ t('View') }}</DropdownItem>
                <DropdownItem v-if="can('Supplier Update')" :href="route('accounting.suppliers.edit', row.id)"><Pencil />{{ t('Edit') }}</DropdownItem>
            </RowMenu>
        </template>
    </DataTable>

    <RecordViewModal
        v-if="viewing"
        v-model:open="viewOpen"
        :title="viewing.name"
        :fields="[
            { label: t('Name'), value: viewing.name },
            { label: t('Email'), value: viewing.email },
            { label: t('Phone'), value: viewing.phone },
            { label: t('Tax Number'), value: viewing.tax_number },
            { label: t('Address'), value: viewing.address },
        ]"
    />
</template>
