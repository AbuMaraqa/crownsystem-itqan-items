<script setup lang="ts">
import { computed, ref } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { ShieldCheck } from '@/lib/icons';
import { route } from '@/lib/route';
import { useRecordDelete } from '@/lib/useRecordDelete';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import RoleFormModal from './parts/RoleFormModal.vue';
import RolePermissionsModal from './parts/RolePermissionsModal.vue';
import RoleSummaryCell from './parts/RoleSummaryCell.vue';
import type { PermissionGroup, RoleRow } from './parts/types';

defineOptions({ layout: AdminLayout });

const props = defineProps<{ table: TablePayload<RoleRow>; permissionGroups: PermissionGroup[]; permissionOptions: Option[] }>();

const formOpen = ref(false);
const editing = ref<RoleRow | null>(null);
const viewing = ref<RoleRow | null>(null);
const viewOpen = computed({
    get: () => viewing.value !== null,
    set: (open: boolean) => {
        if (!open) viewing.value = null;
    },
});

const columns = computed<TableColumn[]>(() => [
    { key: 'label', label: t('Role Name'), sort: 'name' },
    { key: 'users_count', label: t('Users'), sort: 'users_count', class: 'tabular', toggleable: true },
    { key: 'summary', label: t('Permission Groups') },
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'permission', label: t('Permissions'), options: props.permissionOptions },
    { type: 'boolean', key: 'has_users', label: t('Users'), trueLabel: t('Has users'), falseLabel: t('No users') },
    { type: 'date-range', key: 'created_at', label: t('Created At') },
]);

function openForm(role: RoleRow | null): void {
    editing.value = role;
    formOpen.value = true;
}

const { remove } = useRecordDelete('roles');
</script>

<template>
    <PageHeader :title="t('Roles And Permissions')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Roles And Permissions Create')" variant="primary" @click="openForm(null)">{{ t('Create Role') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Roles And Permissions Delete') ? route('roles.destroy-many') : undefined" :empty-title="t('No roles found')" :empty-text="t('Create a role, then give it to your staff.')">
        <template #cell-summary="{ row }">
            <RoleSummaryCell :summary="row.summary" />
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Roles And Permissions"
                routes="roles"
                :id="row.id"
                :title="row.label"
                :fields="[
                    { label: t('Role Name'), value: row.label },
                    { label: t('Users'), value: row.users_count },
                    { label: t('Permissions'), value: row.summary.total },
                    { label: t('Permission Groups'), value: row.summary.groups.map((group) => group.label).join('، ') },
                ]"
                :editable="row.editable"
                :deletable="!row.protected"
                @edit="openForm(row)"
                @delete="remove(row.id, row.label)"
            >
                <DropdownItem @select="viewing = row"><ShieldCheck />{{ t('View Permissions') }}</DropdownItem>
            </RecordActions>
        </template>
    </DataTable>

    <RoleFormModal v-model:open="formOpen" :role="editing" :permission-groups="permissionGroups" />
    <RolePermissionsModal v-if="viewing" v-model:open="viewOpen" :role="viewing" />
</template>
