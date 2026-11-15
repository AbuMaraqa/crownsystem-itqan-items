<script setup lang="ts">
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import UserCell from '@/Components/table/UserCell.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@modules/Items/resources/js/Pages/parts/RecordActions.vue';
import { useRecordDelete } from '@modules/Items/resources/js/Pages/parts/useRecordDelete';
import { useRecordModal } from '@modules/Items/resources/js/Pages/parts/useRecordModal';

import type { UserCellData } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    created_by: UserCellData | null;
}

defineProps<{ table: TablePayload<Row> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'created_by', label: t('Added By'), toggleable: true },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search by name') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { open, editingId, form, add, edit, submit } = useRecordModal<{ name: string }>('accounting.attachment-categories', () => ({ name: '' }));

const { remove } = useRecordDelete('accounting.attachment-categories');

function editRow(row: Row): void {
    edit(row.id, { name: row.name });
}
</script>

<template>
    <PageHeader :title="t('Attachment Categories')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Attachment Category Create')" variant="primary" @click="add">{{ t('Add Attachment Category') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No data available')">
        <template #cell-name="{ row }">
            <button v-if="can('Attachment Category Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
        </template>
        <template #cell-created_by="{ row }">
            <UserCell v-if="row.created_by" :name="row.created_by.name" :avatar="row.created_by.avatar" :detail="row.created_by.detail" />
            <span v-else class="text-muted">—</span>
        </template>
        <template #empty>
            <Button v-if="can('Attachment Category Create')" variant="primary" @click="add">{{ t('Add Attachment Category') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Attachment Category"
                routes="accounting.attachment-categories"
                :id="row.id"
                :title="row.name"
                :fields="[{ label: t('Name'), value: row.name }]"
                @edit="editRow(row)"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Attachment Category')" width="max-w-md">
        <form id="attachment-categories-form" class="grid gap-4" @submit.prevent="submit">
            <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
