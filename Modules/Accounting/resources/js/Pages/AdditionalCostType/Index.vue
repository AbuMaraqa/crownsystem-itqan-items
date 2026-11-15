<script setup lang="ts">
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import DataTable from '@/Components/table/DataTable.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Switch from '@/Components/ui/Switch.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@modules/Items/resources/js/Pages/parts/RecordActions.vue';
import { useRecordDelete } from '@modules/Items/resources/js/Pages/parts/useRecordDelete';
import { useRecordModal } from '@modules/Items/resources/js/Pages/parts/useRecordModal';

import { useActiveToggle } from '../parts/useActiveToggle';
import type { UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    is_active: boolean;
    created_by: UserCellData | null;
}

defineProps<{ table: TablePayload<Row> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'is_active', label: t('Active') },
    { key: 'created_by', label: t('Added By'), toggleable: true },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search by name') },
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { open, editingId, form, add, edit, submit } = useRecordModal<{ name: string; is_active: boolean }>('accounting.additional-cost-types', () => ({ name: '', is_active: true }));

const { remove } = useRecordDelete('accounting.additional-cost-types');

const toggleActive = useActiveToggle('accounting.additional-cost-types');

function editRow(row: Row): void {
    edit(row.id, { name: row.name, is_active: row.is_active });
}
</script>

<template>
    <PageHeader :title="t('Additional Cost Items')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Additional Cost Type Create')" variant="primary" @click="add">{{ t('Add Cost Item') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No data available')">
        <template #cell-name="{ row }">
            <button v-if="can('Additional Cost Type Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
        </template>
        <template #cell-is_active="{ row }">
            <Switch :model-value="row.is_active" :disabled="!can('Additional Cost Type Update')" :aria-label="t('Active')" @update:model-value="(value: boolean) => toggleActive(row.id, value)" />
        </template>
        <template #cell-created_by="{ row }">
            <UserCell v-if="row.created_by" :name="row.created_by.name" :avatar="row.created_by.avatar" :detail="row.created_by.detail" />
            <span v-else class="text-muted">—</span>
        </template>
        <template #empty>
            <Button v-if="can('Additional Cost Type Create')" variant="primary" @click="add">{{ t('Add Cost Item') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Additional Cost Type"
                routes="accounting.additional-cost-types"
                :id="row.id"
                :title="row.name"
                :fields="[{ label: t('Name'), value: row.name }, { label: t('Active'), value: row.is_active ? t('Yes') : t('No') }]"
                @edit="editRow(row)"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Cost Item')" width="max-w-md">
        <form id="additional-cost-types-form" class="grid gap-4" @submit.prevent="submit">
            <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
            <Toggle v-model="form.is_active" :label="t('Active')" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
