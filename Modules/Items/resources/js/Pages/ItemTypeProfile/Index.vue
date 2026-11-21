<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import RecordActions from '@/Components/table/RecordActions.vue';
import ItemTypeProfileFormModal from './parts/ItemTypeProfileFormModal.vue';
import type { ItemTypeModes, ItemTypeProfileRow } from './parts/types';

defineOptions({ layout: AdminLayout });

const props = defineProps<{ table: TablePayload<ItemTypeProfileRow>; modes: ItemTypeModes }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'code', label: t('Code'), sort: 'code' },
    { key: 'name', label: t('Name') },
    { key: 'quantity_decimals', label: t('Quantity Decimals'), class: 'tabular' },
    { key: 'price_decimals', label: t('Price Decimals'), class: 'tabular' },
    { key: 'sub_item_mode', label: t('Sub Items') },
    { key: 'serial_mode', label: t('Serial') },
    { key: 'date_mode', label: t('Date') },
    { key: 'bonus_mode', label: t('Free Quantity'), toggleable: true, hidden: true },
    { key: 'notes_count', label: t('Line Notes'), class: 'tabular', toggleable: true, hidden: true },
    { key: 'dimensions', label: t('Dimensions'), class: 'tabular', toggleable: true, hidden: true },
    { key: 'sort_order', label: t('Sort Order'), sort: 'sort_order', class: 'tabular', toggleable: true, hidden: true },
    { key: 'is_active', label: t('Is Active') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { key: 'sub_item_mode', label: t('Sub Items'), options: props.modes.subItem },
    { key: 'serial_mode', label: t('Serial'), options: props.modes.serial },
    { key: 'date_mode', label: t('Date'), options: props.modes.date },
    { key: 'bonus_mode', label: t('Free Quantity'), options: props.modes.bonus },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

function label(options: { value: string | number; label: string }[], value: string): string {
    return options.find((option) => String(option.value) === value)?.label ?? value;
}

const formOpen = ref(false);
const editing = ref<ItemTypeProfileRow | null>(null);
function openForm(row: ItemTypeProfileRow | null): void {
    editing.value = row;
    formOpen.value = true;
}

const yesNo = (value: boolean): string => (value ? t('Yes') : t('No'));
const decimalsText = (value: number | null): string => (value === null ? t('Hidden') : String(value));

/** What the View action shows: every field of the form. */
function viewFields(row: ItemTypeProfileRow): { label: string; value: string }[] {
    return [
        { label: t('Code'), value: row.code },
        { label: t('Name'), value: row.name },
        { label: t('Sort Order'), value: String(row.sort_order) },
        { label: t('Quantity Decimals'), value: decimalsText(row.quantity_decimals) },
        { label: t('Price Decimals'), value: decimalsText(row.price_decimals) },
        { label: t('Line Notes'), value: String(row.notes_count) },
        { label: t('Date'), value: label(props.modes.date, row.date_mode) },
        { label: t('Free Quantity'), value: label(props.modes.bonus, row.bonus_mode) },
        { label: t('Serial'), value: label(props.modes.serial, row.serial_mode) },
        { label: t('Sub Items'), value: label(props.modes.subItem, row.sub_item_mode) },
        { label: t('Dimensions'), value: String(row.dimensions) },
        { label: t('Has Color'), value: yesNo(row.has_color) },
        { label: t('Has Size'), value: yesNo(row.has_size) },
        { label: t('Is Active'), value: yesNo(row.is_active) },
    ];
}

async function remove(row: ItemTypeProfileRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.name }), description: t('This cannot be undone.') })) {
        router.delete(route('item-type-profiles.destroy', row.id), { preserveScroll: true });
    }
}

async function removeMany(ids: (number | string)[]): Promise<void> {
    if (await confirm({ title: t('Delete :count items?', { count: ids.length }), description: t('Item types used by items are not deleted.') })) {
        router.delete(route('item-type-profiles.destroy-many'), { data: { ids }, preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Item Types List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Item Type Profile Create')" variant="primary" @click="openForm(null)">{{ t('Add Item Type') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :selectable="can('Item Type Profile Delete')" :reorder-url="can('Item Type Profile Update') ? route('item-type-profiles.reorder') : undefined" :empty-title="t('No item types found')">
        <template #cell-code="{ row }">
            <bdi>{{ row.code }}</bdi>
        </template>
        <template #cell-name="{ row }">
            <button v-if="can('Item Type Profile Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="openForm(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
        </template>
        <template #cell-quantity_decimals="{ row }">
            <span :class="{ 'text-muted': row.quantity_decimals === null }">{{ row.quantity_decimals ?? t('Hidden') }}</span>
        </template>
        <template #cell-price_decimals="{ row }">
            <span :class="{ 'text-muted': row.price_decimals === null }">{{ row.price_decimals ?? t('Hidden') }}</span>
        </template>
        <template #cell-sub_item_mode="{ row }">{{ label(modes.subItem, row.sub_item_mode) }}</template>
        <template #cell-serial_mode="{ row }">{{ label(modes.serial, row.serial_mode) }}</template>
        <template #cell-date_mode="{ row }">{{ label(modes.date, row.date_mode) }}</template>
        <template #cell-bonus_mode="{ row }">{{ label(modes.bonus, row.bonus_mode) }}</template>
        <template #cell-is_active="{ row }">
            <Badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('Active') : t('Inactive') }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can('Item Type Profile Create')" variant="primary" @click="openForm(null)">{{ t('Add Item Type') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Item Type Profile"
                routes="item-type-profiles"
                :id="row.id"
                :title="row.name"
                :fields="viewFields(row)"
                @edit="openForm(row)"
                @delete="remove(row)"
            />
        </template>
        <template #bulk="{ selected }">
            <Button variant="danger" size="sm" @click="removeMany(selected)">{{ t('Delete') }}</Button>
        </template>
    </DataTable>

    <ItemTypeProfileFormModal v-model:open="formOpen" :profile="editing" :modes="modes" />
</template>
