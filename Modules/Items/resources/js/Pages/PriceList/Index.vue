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
import PriceListFormModal, { type PriceListRow } from './parts/PriceListFormModal.vue';

defineOptions({ layout: AdminLayout });

defineProps<{ table: TablePayload<PriceListRow> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'name', label: t('Name') },
    { key: 'same_buy_sell', label: t('Buy Price Equals Sell Price') },
    { key: 'prices_count', label: t('Items Priced'), sort: 'prices_count', class: 'tabular' },
    { key: 'sort_order', label: t('Sort Order'), sort: 'sort_order', class: 'tabular', toggleable: true },
    { key: 'is_active', label: t('Is Active') },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'boolean', key: 'same_buy_sell', label: t('Buy Price Equals Sell Price'), trueLabel: t('Yes'), falseLabel: t('No') },
    { type: 'number-range', key: 'sort_order', label: t('Sort Order') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

const formOpen = ref(false);
const editing = ref<PriceListRow | null>(null);

function openForm(row: PriceListRow | null): void {
    editing.value = row;
    formOpen.value = true;
}

const yesNo = (value: boolean): string => (value ? t('Yes') : t('No'));

async function remove(row: PriceListRow): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.name }), description: t('This cannot be undone.') })) {
        router.delete(route('price-lists.destroy', row.id), { preserveScroll: true });
    }
}

async function removeMany(ids: (number | string)[]): Promise<void> {
    if (await confirm({ title: t('Delete :count items?', { count: ids.length }), description: t('Price lists with prices are not deleted.') })) {
        router.delete(route('price-lists.destroy-many'), { data: { ids }, preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Price Lists')" :count="table.meta.total" :description="t('The prices of each item are entered on its units and prices page.')">
        <template #actions>
            <Button v-if="can('Price List Create')" variant="primary" @click="openForm(null)">{{ t('Add Price List') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :selectable="can('Price List Delete')" :reorder-url="can('Price List Update') ? route('price-lists.reorder') : undefined" :empty-title="t('No price lists found')">
        <template #cell-name="{ row }">
            <button v-if="can('Price List Update')" type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="openForm(row)">{{ row.name }}</button>
            <span v-else class="font-medium">{{ row.name }}</span>
        </template>
        <template #cell-same_buy_sell="{ row }">
            <Badge :tone="row.same_buy_sell ? 'success' : 'neutral'">{{ yesNo(row.same_buy_sell) }}</Badge>
        </template>
        <template #cell-is_active="{ row }">
            <Badge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('Active') : t('Inactive') }}</Badge>
        </template>
        <template #empty>
            <Button v-if="can('Price List Create')" variant="primary" @click="openForm(null)">{{ t('Add Price List') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Price List"
                routes="price-lists"
                :id="row.id"
                :title="row.name"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Sort Order'), value: String(row.sort_order) },
                    { label: t('Buy Price Equals Sell Price'), value: yesNo(row.same_buy_sell) },
                    { label: t('Is Active'), value: yesNo(row.is_active) },
                    { label: t('Items Priced'), value: String(row.prices_count) },
                ]"
                @edit="openForm(row)"
                @delete="remove(row)"
            />
        </template>
        <template #bulk="{ selected }">
            <Button variant="danger" size="sm" @click="removeMany(selected)">{{ t('Delete') }}</Button>
        </template>
    </DataTable>

    <PriceListFormModal v-model:open="formOpen" :list="editing" />
</template>
