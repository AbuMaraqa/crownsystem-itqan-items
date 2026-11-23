<script setup lang="ts">
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RecordActions from '@/Components/table/RecordActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import { useRecordDelete } from '@/lib/useRecordDelete';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';
import type { UserCellData } from '@/types/user';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    image: string | null;
    name: string;
    sku: string | null;
    base_price: string | number | null;
    categories: string;
    parent: string | null;
    created_by: UserCellData | null;
}

/** The items linked to a category; variations show their parent item to tell them apart. */
defineProps<{ category: { id: number; name: string; code: string | null }; table: TablePayload<Row> }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'image', label: t('Image') },
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'sku', label: t('SKU'), sort: 'sku', class: 'tabular' },
    { key: 'base_price', label: t('Base Price'), sort: 'base_price', class: 'tabular' },
    { key: 'categories', label: t('Category'), toggleable: true },
    { key: 'parent', label: t('Parent Product'), toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'pricing_type', label: t('Type'), options: [{ value: 'simple', label: t('By Quantity') }, { value: 'weight', label: t('By Weight') }] },
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'number-range', key: 'base_price', label: t('Base Price') },
]);

// The rows are items: their actions and permissions are the items list's (products.*).
const { remove } = useRecordDelete('products');
</script>

<template>
    <PageHeader
        :title="`${t('Category Items')} - ${category.name}`"
        :count="table.meta.total"
        :parents="[
            { title: t('Categories List'), href: route('categories.index') },
            { title: t('Categories Tree'), href: route('categories.tree') },
        ]"
    />

    <DataTable :table="table" :columns="columns" :filters="filters" :bulk-delete-url="can('Product Delete') ? route('products.destroy-many') : undefined" :empty-title="t('No items linked to this category')">
        <template #cell-image="{ row }">
            <img v-if="row.image" :src="row.image" alt="" class="size-12 rounded-control object-cover" />
        </template>
        <template #cell-name="{ row }">
            <a v-if="can('Product Update')" :href="route('products.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ row.name }}</a>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-sku="{ row }"><bdi dir="ltr">{{ row.sku ?? '-' }}</bdi></template>
        <template #cell-parent="{ row }">{{ row.parent ?? '—' }}</template>
        <template #actions="{ row }">
            <RecordActions
                entity="Product"
                routes="products"
                :id="row.id"
                :title="row.name"
                :edit-href="route('products.edit', row.id)"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('SKU'), value: row.sku },
                    { label: t('Base Price'), value: row.base_price },
                    { label: t('Category'), value: row.categories },
                    { label: t('Parent Product'), value: row.parent },
                ]"
                @delete="remove(row.id, row.name)"
            />
        </template>
    </DataTable>
</template>
