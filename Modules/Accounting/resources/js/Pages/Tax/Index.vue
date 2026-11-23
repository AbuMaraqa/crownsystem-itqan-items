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
    code: string | null;
    rate: string;
    rate_label: string;
    is_active: boolean;
}

interface TaxForm {
    name: string;
    code: string;
    rate: string | number;
    is_active: boolean;
}

defineProps<{ table: TablePayload<Row> }>();

const routes = 'accounting.taxes';

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'code', label: t('Tax Code'), sort: 'code' },
    { key: 'rate', label: t('Tax Rate'), sort: 'rate', class: 'tabular' },
    { key: 'is_active', label: t('Active') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'text', key: 'code', label: t('Tax Code') },
    { type: 'number-range', key: 'rate', label: t('Tax Rate') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { open, editingId, form, add, edit, submit } = useRecordModal<TaxForm>(routes, () => ({ name: '', code: '', rate: '', is_active: true }));

const { remove } = useRecordDelete(routes);
const toggleActive = useActiveToggle(routes);

function editRow(row: Row): void {
    edit(row.id, { name: row.name, code: row.code ?? '', rate: row.rate, is_active: row.is_active });
}

/** What the View action shows (the read-only form of the old page). */
function viewFields(row: Row): { label: string; value: string | null }[] {
    return [
        { label: t('Name'), value: row.name },
        { label: t('Tax Code'), value: row.code },
        { label: t('Tax Rate'), value: row.rate_label },
        { label: t('Active'), value: row.is_active ? t('Yes') : t('No') },
    ];
}
</script>

<template>
    <PageHeader :title="t('Taxes')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Tax Create')" variant="primary" @click="add">{{ t('Add Tax') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Tax Delete') ? route('accounting.taxes.destroy-many') : undefined" :reorder-url="can('Tax Update') ? route('accounting.taxes.reorder') : undefined" :empty-title="t('No data available')">
        <template #cell-name="{ row }">
            <button v-if="can('Tax Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
        </template>
        <template #cell-code="{ row }">
            <bdi v-if="row.code">{{ row.code }}</bdi>
            <span v-else class="text-muted">—</span>
        </template>
        <template #cell-rate="{ row }">
            <bdi>{{ row.rate_label }}</bdi>
        </template>
        <template #cell-is_active="{ row }">
            <Switch :model-value="row.is_active" :disabled="!can('Tax Update')" :aria-label="t('Active')" @update:model-value="(value: boolean) => toggleActive(row.id, value)" />
        </template>
        <template #empty>
            <Button v-if="can('Tax Create')" variant="primary" @click="add">{{ t('Add Tax') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions entity="Tax" :routes="routes" :id="row.id" :title="row.name" :fields="viewFields(row)" @edit="editRow(row)" @delete="remove(row.id, row.name)" />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Tax')" width="max-w-lg">
        <form id="tax-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
            <TextInput v-model="form.code" :label="t('Tax Code')" :maxlength="50" dir="ltr" :error="form.errors.code" />
            <TextInput v-model="form.rate" :label="t('Tax Rate')" type="number" min="0" max="100" step="any" required dir="ltr" :error="form.errors.rate" />
            <div class="flex items-end pb-2">
                <Toggle v-model="form.is_active" :label="t('Active')" />
            </div>
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
