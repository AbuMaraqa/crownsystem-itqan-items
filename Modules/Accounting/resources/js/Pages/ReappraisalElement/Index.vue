<script setup lang="ts">
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import DataTable from '@/Components/table/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Switch from '@/Components/ui/Switch.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@/Components/table/RecordActions.vue';
import { useRecordDelete } from '@/lib/useRecordDelete';
import { useRecordModal } from '@/lib/useRecordModal';

import { useActiveToggle } from '../parts/useActiveToggle';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    is_active: boolean;
}

defineProps<{ table: TablePayload<Row> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'is_active', label: t('Active') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search by name') },
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { open, editingId, form, add, edit, submit } = useRecordModal<{ name: string; is_active: boolean }>('accounting.reappraisal-elements', () => ({ name: '', is_active: true }));

const { remove } = useRecordDelete('accounting.reappraisal-elements');

const toggleActive = useActiveToggle('accounting.reappraisal-elements');

function editRow(row: Row): void {
    edit(row.id, { name: row.name, is_active: row.is_active });
}
</script>

<template>
    <PageHeader :title="t('Reappraisal Elements')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Reappraisal Element Create')" variant="primary" @click="add">{{ t('Add Element') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Reappraisal Element Delete') ? route('accounting.reappraisal-elements.destroy-many') : undefined" :reorder-url="can('Reappraisal Element Update') ? route('accounting.reappraisal-elements.reorder') : undefined" :empty-title="t('No data available')">
        <template #cell-name="{ row }">
            <button v-if="can('Reappraisal Element Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
        </template>
        <template #cell-is_active="{ row }">
            <Switch :model-value="row.is_active" :disabled="!can('Reappraisal Element Update')" :aria-label="t('Active')" @update:model-value="(value: boolean) => toggleActive(row.id, value)" />
        </template>
        <template #empty>
            <Button v-if="can('Reappraisal Element Create')" variant="primary" @click="add">{{ t('Add Element') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Reappraisal Element"
                routes="accounting.reappraisal-elements"
                :id="row.id"
                :title="row.name"
                :fields="[{ label: t('Name'), value: row.name }, { label: t('Active'), value: row.is_active ? t('Yes') : t('No') }]"
                @edit="editRow(row)"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Element')" width="max-w-md">
        <form id="reappraisal-elements-form" class="grid gap-4" @submit.prevent="submit">
            <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
            <Toggle v-model="form.is_active" :label="t('Active')" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
