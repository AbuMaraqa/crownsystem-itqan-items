<script setup lang="ts">
import { computed } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import UserCell from '@/Components/table/UserCell.vue';
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
    avatar: string;
    email: string | null;
    phone: string | null;
    roles: { name: string; label: string }[];
    branch: string | null;
    created_at: string | null;
    updated_at: string | null;
    can_manage: boolean;
}

const props = defineProps<{ table: TablePayload<Row>; roleOptions: Option[]; branchOptions: Option[] | null }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'email', label: t('Email'), sort: 'email' },
    { key: 'phone', label: t('Phone'), toggleable: true },
    { key: 'roles', label: t('Roles') },
    ...(props.branchOptions ? [{ key: 'branch', label: t('Branch'), toggleable: true }] : []),
    { key: 'created_at', label: t('Created At'), sort: 'created_at', toggleable: true },
    { key: 'updated_at', label: t('Updated At'), sort: 'updated_at', toggleable: true, hidden: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'role', label: t('Roles'), options: props.roleOptions },
    ...(props.branchOptions ? [{ key: 'branch', label: t('Branch'), options: props.branchOptions }] : []),
    { type: 'text', key: 'name', label: t('Name') },
    { type: 'text', key: 'email', label: t('Email') },
    { type: 'text', key: 'phone', label: t('Phone') },
    { type: 'date-range', key: 'created_at', label: t('Registration Date') },
]);

const { remove } = useRecordDelete('users');
</script>

<template>
    <PageHeader :title="t('Users List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('User Create')" variant="primary" :href="route('users.add')">{{ t('Add User') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('User Delete') ? route('users.destroy-many') : undefined" :empty-title="t('No users found')" :empty-text="t('Add the people who use the system.')">
        <template #cell-name="{ row }">
            <UserCell :name="row.name" :avatar="row.avatar" :href="can('User Update') && row.can_manage ? route('users.edit', row.id) : null" />
        </template>
        <template #cell-email="{ row }">
            <span dir="ltr">{{ row.email }}</span>
        </template>
        <template #cell-phone="{ row }">
            <span dir="ltr">{{ row.phone }}</span>
        </template>
        <template #cell-roles="{ row }">
            <span class="flex flex-wrap gap-1">
                <Badge v-for="role in row.roles" :key="role.name" :tone="role.name === 'Super Admin' ? 'danger' : 'brand'">{{ role.label }}</Badge>
            </span>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="User"
                routes="users"
                :id="row.id"
                :title="row.name"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Email'), value: row.email },
                    { label: t('Phone'), value: row.phone },
                    { label: t('Roles'), value: row.roles.map((role) => role.label).join('، ') },
                    ...(branchOptions ? [{ label: t('Branch'), value: row.branch }] : []),
                    { label: t('Created At'), value: row.created_at },
                ]"
                :edit-href="route('users.edit', row.id)"
                :editable="row.can_manage"
                :deletable="row.can_manage"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>
</template>
