<script setup lang="ts">
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import { useRecordDelete } from '@/lib/useRecordDelete';
import { useRecordModal } from '@/lib/useRecordModal';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import FinishFields, { type FinishForm } from './FinishFields.vue';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    code: string | null;
    color_code: string | null;
    is_active: boolean;
    is_system: boolean;
    categories_count: number;
}

const props = defineProps<{ table: TablePayload<Row>; nextCode: string }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'code', label: t('Code'), sort: 'code' },
    { key: 'categories_count', label: t('Categories'), sort: 'category_finishes_count', class: 'tabular' },
    { key: 'is_active', label: t('Is Active') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search by name') },
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const blank = (): FinishForm => ({ name: '', code: props.nextCode, color_code: '', is_active: true });

const { open, editingId, form, add, edit, submit } = useRecordModal<FinishForm>('finishes', blank);
const { remove } = useRecordDelete('finishes');

const editingSystem = computed(() => props.table.data.find((row) => row.id === editingId.value)?.is_system ?? false);

function editRow(row: Row): void {
    edit(row.id, { name: row.name, code: row.code ?? '', color_code: row.color_code ?? '', is_active: row.is_active });
}
</script>

<template>
    <PageHeader :title="t('Colours List')" :count="table.meta.total" :description="t('One list of colours for all brands. Each category picks its colours and kg prices on its own page.')">
        <template #actions>
            <Button v-if="can('Finish Create')" variant="primary" @click="add">{{ t('Add Colour') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Finish Delete') ? route('finishes.destroy-many') : undefined" :reorder-url="can('Finish Update') ? route('finishes.reorder') : undefined" :empty-title="t('No colours found')">
        <template #cell-name="{ row }">
            <span class="inline-flex items-center gap-2">
                <span class="size-4 shrink-0 rounded-full border border-line" :style="row.color_code ? { background: row.color_code } : undefined" :class="{ 'bg-canvas': !row.color_code }" aria-hidden="true" />
                <button v-if="can('Finish Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)">{{ row.name }}</button>
                <span v-else class="font-medium">{{ row.name }}</span>
                <Badge v-if="row.is_system" tone="info">{{ t('System') }}</Badge>
            </span>
        </template>
        <template #cell-code="{ row }">
            <span dir="ltr">{{ row.code }}</span>
        </template>
        <template #cell-is_active="{ row }">
            <Badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('Active') : t('Inactive') }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can('Finish Create')" variant="primary" @click="add">{{ t('Add Colour') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Finish"
                routes="finishes"
                :id="row.id"
                :title="row.name"
                :deletable="!row.is_system"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Code'), value: row.code },
                    { label: t('Color'), value: row.color_code },
                    { label: t('Categories'), value: String(row.categories_count) },
                ]"
                @edit="editRow(row)"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit Colour') : t('Add Colour')" width="max-w-md">
        <form @submit.prevent="submit">
            <FinishFields :form="form" :system="editingSystem" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
