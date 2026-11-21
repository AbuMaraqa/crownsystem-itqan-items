<script setup lang="ts">
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@/Components/table/RecordActions.vue';
import { useRecordDelete } from '@/lib/useRecordDelete';
import { useRecordModal } from '@/lib/useRecordModal';


defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
}

defineProps<{ table: TablePayload<Row> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    createdByColumn(),
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

    <DataTable :table="table" :columns="columns" :filters="filters" :reorder-url="can('Attachment Category Update') ? route('accounting.attachment-categories.reorder') : undefined" :empty-title="t('No data available')">
        <template #cell-name="{ row }">
            <button v-if="can('Attachment Category Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
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
