<script setup lang="ts">
import { computed, ref } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import RemoteMultiSelect from '@/Components/form/RemoteMultiSelect.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import DataTable from '@/Components/table/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
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

import type { Option } from '../parts/types';
import { useActiveToggle } from '../parts/useActiveToggle';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    name: string;
    profit_margin: string;
    customers_count: number;
    price_list_id: number | null;
    use_last_price: boolean;
    notes: string | null;
    is_active: boolean;
    customers: Option[];
}

interface GroupForm {
    name: string;
    profit_margin: string | number;
    price_list_id: number | null;
    use_last_price: boolean;
    customer_ids: (number | string)[];
    notes: string;
    is_active: boolean;
}

const props = defineProps<{ table: TablePayload<Row>; priceLists: Option[] }>();

const routes = 'accounting.customer-groups';

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'profit_margin', label: t('Profit Margin'), sort: 'profit_margin', class: 'tabular' },
    { key: 'customers_count', label: t('Customers'), sort: 'customers_count' },
    { key: 'price_list_id', label: t('Price List'), toggleable: true, hidden: true },
    { key: 'is_active', label: t('Active') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { key: 'price_list_id', label: t('Price List'), options: props.priceLists },
    { type: 'boolean', key: 'use_last_price', label: t('Use Last Price'), trueLabel: t('Yes'), falseLabel: t('No') },
    { type: 'number-range', key: 'profit_margin', label: t('Profit Margin') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const blank = (): GroupForm => ({ name: '', profit_margin: 0, price_list_id: null, use_last_price: false, customer_ids: [], notes: '', is_active: true });

const { open, editingId, form, add, edit, submit } = useRecordModal<GroupForm>(routes, blank);
const { remove } = useRecordDelete(routes);
const toggleActive = useActiveToggle(routes);

/** The saved customers' labels for the customers field; a new key remounts it with them. */
const initialCustomers = ref<Option[]>([]);
const formKey = ref(0);

function addGroup(): void {
    initialCustomers.value = [];
    formKey.value++;
    add();
}

function editRow(row: Row): void {
    initialCustomers.value = row.customers;
    formKey.value++;
    edit(row.id, {
        name: row.name,
        profit_margin: row.profit_margin,
        price_list_id: row.price_list_id,
        use_last_price: row.use_last_price,
        customer_ids: row.customers.map((customer) => customer.value),
        notes: row.notes ?? '',
        is_active: row.is_active,
    });
}

const priceListName = (id: number | null): string | null => props.priceLists.find((list) => list.value === id)?.label ?? null;
const yesNo = (value: boolean): string => (value ? t('Yes') : t('No'));

function viewFields(row: Row): { label: string; value: string | number | null }[] {
    return [
        { label: t('Name'), value: row.name },
        { label: t('Profit Margin'), value: `${row.profit_margin}%` },
        { label: t('Price List'), value: priceListName(row.price_list_id) },
        { label: t('Use Last Price'), value: yesNo(row.use_last_price) },
        { label: t('Active'), value: yesNo(row.is_active) },
        { label: t('Customers'), value: row.customers.map((customer) => customer.label).join(', ') },
        { label: t('Notes'), value: row.notes },
    ];
}
</script>

<template>
    <PageHeader :title="t('Customer Groups')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Customer Group Create')" variant="primary" @click="addGroup">{{ t('Add Customer Group') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Customer Group Delete') ? route('accounting.customer-groups.destroy-many') : undefined" :reorder-url="can('Customer Group Update') ? route('accounting.customer-groups.reorder') : undefined" :empty-title="t('No data available')">
        <template #cell-name="{ row }">
            <button v-if="can('Customer Group Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="editRow(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
        </template>
        <template #cell-profit_margin="{ row }">
            <bdi>{{ row.profit_margin }}%</bdi>
        </template>
        <template #cell-customers_count="{ row }">
            <Badge tone="info">{{ row.customers_count }}</Badge>
        </template>
        <template #cell-price_list_id="{ row }">
            <span v-if="row.price_list_id">{{ priceListName(row.price_list_id) }}</span>
            <span v-else class="text-muted">—</span>
        </template>
        <template #cell-is_active="{ row }">
            <Switch :model-value="row.is_active" :disabled="!can('Customer Group Update')" :aria-label="t('Active')" @update:model-value="(value: boolean) => toggleActive(row.id, value)" />
        </template>
        <template #empty>
            <Button v-if="can('Customer Group Create')" variant="primary" @click="addGroup">{{ t('Add Customer Group') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions entity="Customer Group" :routes="routes" :id="row.id" :title="row.name" :fields="viewFields(row)" @edit="editRow(row)" @delete="remove(row.id, row.name)" />
        </template>
    </DataTable>

    <Modal v-model:open="open" :title="editingId ? t('Edit') : t('Add Customer Group')" width="max-w-2xl">
        <form id="customer-group-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
            <TextInput
                v-model="form.profit_margin"
                :label="`${t('Profit Margin')} (%)`"
                type="number"
                min="0"
                max="1000"
                step="any"
                required
                dir="ltr"
                :hint="t('Added to the item cost price when pricing sales documents for the group customers.')"
                :error="form.errors.profit_margin"
            />
            <MultiSelect v-model="form.price_list_id" single :options="priceLists" :label="t('Price List')" :error="form.errors.price_list_id" />
            <Toggle v-model="form.use_last_price" :label="t('Use Last Price')" :hint="t('Fills a line with the last posted price for this party and item.')" />
            <div class="sm:col-span-2">
                <RemoteMultiSelect
                    :key="formKey"
                    v-model="form.customer_ids"
                    :url="route('accounting.customer-groups.customers')"
                    :initial="initialCustomers"
                    :label="t('Customers')"
                    :hint="t('A customer with a profit margin of their own keeps it instead of the group margin.')"
                    :error="form.errors.customer_ids"
                />
            </div>
            <div class="sm:col-span-2">
                <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :error="form.errors.notes" />
            </div>
            <Toggle v-model="form.is_active" :label="t('Active')" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
