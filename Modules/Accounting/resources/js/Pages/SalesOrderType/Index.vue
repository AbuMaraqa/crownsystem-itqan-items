<script setup lang="ts">
/**
 * Sales order types from the company's order-types guide: each type has a maximum lead time, in days
 * from the customer's confirmation, for the order to be prepared and ready to invoice.
 */
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Switch from '@/Components/ui/Switch.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import { useRecordDelete } from '@/lib/useRecordDelete';
import { useRecordModal } from '@/lib/useRecordModal';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import { useActiveToggle } from '../parts/useActiveToggle';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    description: string | null;
    lead_days: number;
    is_active: boolean;
}

interface TypeForm {
    name: string;
    description: string;
    lead_days: number | string;
    is_active: boolean;
}

defineProps<{ table: TablePayload<Row> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Order Type'), sort: 'name' },
    { key: 'description', label: t('When to use') },
    { key: 'lead_days', label: t('Maximum lead time'), sort: 'lead_days', class: 'tabular' },
    { key: 'is_active', label: t('Active') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search by name') },
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const routes = 'accounting.sales-order-types';
const blank = (): TypeForm => ({ name: '', description: '', lead_days: '', is_active: true });
const { open, editingId, form, add, edit, submit } = useRecordModal<TypeForm>(routes, blank);
const { remove } = useRecordDelete(routes);
const toggleActive = useActiveToggle(routes);

const days = (count: number): string => t(':count days', { count });

function editRow(row: Row): void {
    edit(row.id, { name: row.name, description: row.description ?? '', lead_days: row.lead_days, is_active: row.is_active });
}
</script>

<template>
    <PageHeader :title="t('Sales Order Types')" :count="table.meta.total" :description="t('Each type sets the maximum time, from the customer\'s confirmation, for the order to be prepared and ready to invoice.')">
        <template #actions>
            <Button v-if="can('Sales Order Type Create')" variant="primary" @click="add">{{ t('Add Order Type') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Sales Order Type Delete') ? route(`${routes}.destroy-many`) : undefined" :reorder-url="can('Sales Order Type Update') ? route(`${routes}.reorder`) : undefined" :empty-title="t('No data available')">
        <template #cell-name="{ row }">
            <button v-if="can('Sales Order Type Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
        </template>
        <template #cell-description="{ row }">
            <span class="text-muted">{{ row.description }}</span>
        </template>
        <template #cell-lead_days="{ row }">{{ days(row.lead_days) }}</template>
        <template #cell-is_active="{ row }">
            <Switch :model-value="row.is_active" :disabled="!can('Sales Order Type Update')" :aria-label="t('Active')" @update:model-value="(value: boolean) => toggleActive(row.id, value)" />
        </template>
        <template #empty>
            <Button v-if="can('Sales Order Type Create')" variant="primary" @click="add">{{ t('Add Order Type') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Sales Order Type"
                :routes="routes"
                :id="row.id"
                :title="row.name"
                :fields="[
                    { label: t('Order Type'), value: row.name },
                    { label: t('When to use'), value: row.description },
                    { label: t('Maximum lead time'), value: days(row.lead_days) },
                    { label: t('Active'), value: row.is_active ? t('Yes') : t('No') },
                ]"
                @edit="editRow(row)"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit Order Type') : t('Add Order Type')" width="max-w-md">
        <form class="grid gap-4" @submit.prevent="submit">
            <TextInput v-model="form.name" :label="t('Order Type')" required :maxlength="255" :error="form.errors.name" />
            <TextInput v-model="form.description" :label="t('When to use')" :maxlength="255" :error="form.errors.description" />
            <TextInput v-model="form.lead_days" type="number" min="1" step="1" :label="t('Maximum lead time (days)')" required :hint="t('Counted from the customer\'s confirmation of the order.')" :error="form.errors.lead_days" />
            <Toggle v-model="form.is_active" :label="t('Active')" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
