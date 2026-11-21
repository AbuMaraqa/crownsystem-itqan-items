<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import DataTable from '@/Components/table/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import RecordActions from '@/Components/table/RecordActions.vue';
import { useRecordDelete } from '@/lib/useRecordDelete';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    image: string;
    name: string;
    branch: string | null;
    description: string | null;
    offerable_model: string | null;
    offerable_id: number;
    offerable: string | null;
    is_active: boolean;
    type: string | null;
    value: string | number;
    code: string | null;
    start_date: string | null;
    end_date: string | null;
}

const props = defineProps<{ table: TablePayload<Row>; types: Option[]; branches: Option[] }>();

const targetModels = computed<Option[]>(() => [
    { value: 'items_category', label: t('Category') },
    { value: 'items_product', label: t('Product') },
]);

const targetLabel = (value: string | null): string => targetModels.value.find((model) => model.value === value)?.label ?? '';

const columns = computed<TableColumn[]>(() => [
    { key: 'image', label: t('Image') },
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'branch', label: t('Branch'), toggleable: true },
    { key: 'description', label: t('Description'), toggleable: true },
    { key: 'offerable_model', label: t('Offerable Type'), toggleable: true },
    { key: 'offerable', label: t('Offerable ID'), toggleable: true },
    { key: 'is_active', label: t('Active'), sort: 'is_active' },
    { key: 'type', label: t('Type'), sort: 'type' },
    { key: 'value', label: t('Value'), sort: 'value', class: 'tabular', toggleable: true, hidden: true },
    { key: 'code', label: t('Code'), toggleable: true, hidden: true },
    { key: 'start_date', label: t('Start Date'), sort: 'start_date', toggleable: true },
    { key: 'end_date', label: t('End Date'), sort: 'end_date', toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search...') },
    { type: 'text', key: 'code', label: t('Code') },
    { key: 'type', label: t('Type'), options: props.types },
    { type: 'boolean', key: 'is_active', label: t('Active'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { key: 'branch_id', label: t('Branch'), options: props.branches },
    { key: 'offerable_model', label: t('Offerable Type'), options: targetModels.value },
    { type: 'number-range', key: 'value', label: t('Value') },
    { type: 'date-range', key: 'start_date', label: t('Start Date') },
    { type: 'date-range', key: 'end_date', label: t('End Date') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const { remove, removeMany } = useRecordDelete('offers');
</script>

<template>
    <PageHeader :title="t('Offers List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Offer Create')" variant="primary" :href="route('offers.add')">{{ t('Add Offer') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :selectable="can('Offer Delete')" :reorder-url="can('Offer Update') ? route('offers.reorder') : undefined" :empty-title="t('No offers found')" :empty-text="t('Create a new offer by clicking the button below')">
        <template #cell-image="{ row }">
            <img v-if="row.image" :src="row.image" alt="" class="size-12 rounded-control object-cover" />
        </template>
        <template #cell-name="{ row }">
            <Link v-if="can('Offer Update')" :href="route('offers.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ row.name }}</Link>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-offerable_model="{ row }">{{ targetLabel(row.offerable_model) }}</template>
        <template #cell-offerable="{ row }">{{ row.offerable ?? row.offerable_id }}</template>
        <template #cell-is_active="{ row }">
            <Badge :tone="row.is_active ? 'success' : 'danger'">{{ row.is_active ? t('Active') : t('Inactive') }}</Badge>
        </template>
        <template #cell-type="{ row }">
            <Badge v-if="row.type" tone="brand">{{ row.type }}</Badge>
        </template>
        <template #cell-code="{ row }">
            <span dir="ltr">{{ row.code }}</span>
        </template>
        <template #cell-start_date="{ row }">
            <span class="tabular-nums" dir="ltr">{{ row.start_date }}</span>
        </template>
        <template #cell-end_date="{ row }">
            <span class="tabular-nums" dir="ltr">{{ row.end_date }}</span>
        </template>
        <template #empty>
            <Button v-if="can('Offer Create')" variant="primary" :href="route('offers.add')">{{ t('Add Offer') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Offer"
                routes="offers"
                :id="row.id"
                :title="row.name"
                :edit-href="route('offers.edit', row.id)"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Description'), value: row.description },
                ]"
                @delete="remove(row.id, row.name)"
            />
        </template>
        <template #bulk="{ selected }">
            <Button variant="danger" size="sm" @click="removeMany(selected)">{{ t('Delete') }}</Button>
        </template>
    </DataTable>
</template>
