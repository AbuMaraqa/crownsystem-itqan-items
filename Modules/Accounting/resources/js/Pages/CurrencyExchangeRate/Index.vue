<script setup lang="ts">
import { computed } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import DataTable from '@/Components/table/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import RecordActions from '@/Components/table/RecordActions.vue';
import { useRecordDelete } from '@/lib/useRecordDelete';
import { useRecordModal } from '@/lib/useRecordModal';

import type { Option } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    base_currency: string | null;
    currency_id: number;
    currency: string | null;
    rate_date: string | null;
    rate_date_label: string | null;
    exchange_rate: string;
    exchange_rate_label: string;
    is_active: boolean;
}

interface RateForm {
    currency_id: number | null;
    rate_date: string;
    exchange_rate: string | number;
    is_active: boolean;
}

const props = defineProps<{ table: TablePayload<Row>; currencies: Option[]; baseCurrency: string | null; today: string }>();

const routes = 'accounting.currency-exchange-rates';

const columns = computed<TableColumn[]>(() => [
    { key: 'base_currency', label: t('Base Currency'), sort: 'base_currency' },
    { key: 'currency', label: t('Currency'), sort: 'currency' },
    { key: 'rate_date', label: t('Rate Date'), sort: 'rate_date' },
    { key: 'exchange_rate', label: t('Exchange Rate'), sort: 'exchange_rate', class: 'tabular' },
    { key: 'is_active', label: t('Active') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'currency_id', label: t('Currency'), options: props.currencies },
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'date-range', key: 'rate_date', label: t('Rate Date') },
    { type: 'number-range', key: 'exchange_rate', label: t('Exchange Rate') },
]);

const { open, editingId, form, add, edit, submit } = useRecordModal<RateForm>(routes, () => ({
    currency_id: null,
    rate_date: props.today,
    exchange_rate: '',
    is_active: true,
}));

const { remove } = useRecordDelete(routes);

/** The base currency of the rate being edited; a new rate takes the system's. */
const editingBase = computed(() => props.table.data.find((row) => row.id === editingId.value)?.base_currency ?? props.baseCurrency);

const title = (row: Row): string => `${row.currency ?? ''} — ${row.rate_date_label ?? ''}`;

function editRow(row: Row): void {
    edit(row.id, { currency_id: row.currency_id, rate_date: row.rate_date ?? '', exchange_rate: row.exchange_rate, is_active: row.is_active });
}

function viewFields(row: Row): { label: string; value: string | null }[] {
    return [
        { label: t('Base Currency'), value: row.base_currency },
        { label: t('Currency'), value: row.currency },
        { label: t('Rate Date'), value: row.rate_date },
        { label: t('Exchange Rate'), value: row.exchange_rate_label },
        { label: t('Active'), value: row.is_active ? t('Yes') : t('No') },
    ];
}
</script>

<template>
    <PageHeader :title="t('Currency Exchange Rates')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Currency Exchange Rate Create')" variant="primary" @click="add">{{ t('Add Exchange Rate') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :searchable="false" :empty-title="t('No data available')">
        <template #cell-base_currency="{ row }">
            <bdi>{{ row.base_currency ?? '—' }}</bdi>
        </template>
        <template #cell-currency="{ row }">
            <button v-if="can('Currency Exchange Rate Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)"><bdi>{{ row.currency ?? '—' }}</bdi></button>
            <bdi v-else class="font-medium">{{ row.currency ?? '—' }}</bdi>
        </template>
        <template #cell-rate_date="{ row }">
            <bdi>{{ row.rate_date_label }}</bdi>
        </template>
        <template #cell-exchange_rate="{ row }">
            <bdi>{{ row.exchange_rate_label }}</bdi>
        </template>
        <template #cell-is_active="{ row }">
            <Badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('Active') : t('Inactive') }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can('Currency Exchange Rate Create')" variant="primary" @click="add">{{ t('Add Exchange Rate') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions entity="Currency Exchange Rate" :routes="routes" :id="row.id" :title="title(row)" :fields="viewFields(row)" @edit="editRow(row)" @delete="remove(row.id, title(row))" />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Exchange Rate')" width="max-w-lg">
        <form id="currency-exchange-rate-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <TextInput :model-value="editingBase ?? ''" :label="t('Base Currency')" disabled dir="ltr" :error="(form.errors as Record<string, string>).base_currency_id" />
            <MultiSelect v-model="form.currency_id" single :options="currencies" :label="t('Currency')" required :error="form.errors.currency_id" />
            <TextInput v-model="form.rate_date" :label="t('Rate Date')" type="date" required dir="ltr" :error="form.errors.rate_date" />
            <TextInput v-model="form.exchange_rate" :label="t('Exchange Rate')" type="number" min="0.00000001" step="any" required dir="ltr" :error="form.errors.exchange_rate" />
            <Toggle v-model="form.is_active" :label="t('Active')" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
