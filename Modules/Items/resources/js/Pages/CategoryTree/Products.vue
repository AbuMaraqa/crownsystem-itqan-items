<script setup lang="ts">
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import RowMenu from '@/Components/table/RowMenu.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { Pencil } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    image: string | null;
    name: string;
    sku: string | null;
    base_price: string | number | null;
    categories: string;
    parent: string | null;
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
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'pricing_type', label: t('Type'), options: [{ value: 'simple', label: t('By Quantity') }, { value: 'weight', label: t('By Weight') }] },
    { type: 'boolean', key: 'is_active', label: t('Status'), trueLabel: t('Active'), falseLabel: t('Inactive') },
    { type: 'number-range', key: 'base_price', label: t('Base Price') },
]);
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

    <DataTable :table="table" :columns="columns" :filters="filters" :empty-title="t('No items linked to this category')">
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
            <RowMenu v-if="can('Product Update')">
                <DropdownItem :href="route('products.edit', row.id)"><Pencil />{{ t('Edit') }}</DropdownItem>
            </RowMenu>
        </template>
    </DataTable>
</template>
