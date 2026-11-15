<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import DataTable from '@/Components/table/DataTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Package } from '@/lib/icons';
import { route } from '@/lib/route';
import type { FilterOption, TableColumn, TableFilter, TablePayload } from '@/types/table';

import RecordActions from '../parts/RecordActions.vue';

defineOptions({ layout: AdminLayout });

interface Row {
    id: number;
    image: string | null;
    name: string;
    code: string | null;
    slug: string;
    description: string | null;
    parent: string | null;
    active: boolean;
    locales: string[];
}

const props = defineProps<{ table: TablePayload<Row>; parents: FilterOption[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'image', label: t('Image') },
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'code', label: t('Code'), sort: 'code', class: 'tabular' },
    { key: 'slug', label: t('Slug'), sort: 'slug', toggleable: true },
    { key: 'parent', label: t('Parent Category') },
    { key: 'active', label: t('Status'), toggleable: true },
    { key: 'locales', label: t('Locale'), toggleable: true },
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search by name') },
    { key: 'parent_id', label: t('Parent Category'), options: props.parents },
    { key: 'status', label: t('Status'), options: [{ value: 1, label: t('Active') }, { value: 0, label: t('Inactive') }] },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

// The name opens the category's items page when the user may list items, else its form.
function nameHref(row: Row): string | null {
    if (can('Product View List')) return route('categories.products', row.id);
    if (can('Category Update')) return route('categories.edit', row.id);

    return null;
}

async function remove(row: Row): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: row.name }), description: t('A category that still has subcategories or items is not deleted.') })) {
        router.delete(route('categories.destroy', row.id), { preserveScroll: true });
    }
}

async function removeMany(ids: (number | string)[]): Promise<void> {
    if (await confirm({ title: t('Delete :count items?', { count: ids.length }), description: t('A category that still has subcategories or items is not deleted.') })) {
        router.delete(route('categories.destroy-many'), { data: { ids }, preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader :title="t('Categories List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Category Create')" variant="primary" :href="route('categories.add')">{{ t('Add Category') }}</Button>
        </template>
    </PageHeader>

    <DataTable :table="table" :columns="columns" :filters="filters" :selectable="can('Category Delete')" :empty-title="t('No categories found')" :empty-text="t('Add the categories your items belong to.')">
        <template #cell-image="{ row }">
            <img v-if="row.image" :src="row.image" alt="" class="size-12 rounded-control object-cover" />
        </template>
        <template #cell-name="{ row }">
            <Link
                v-if="nameHref(row)"
                :href="nameHref(row)!"
                :title="can('Product View List') ? t('Show linked items') : undefined"
                class="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:underline dark:text-brand-400"
            >
                <Package class="size-4 shrink-0" />{{ row.name }}
            </Link>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-code="{ row }">
            <bdi class="text-muted">{{ row.code ?? '-' }}</bdi>
        </template>
        <template #cell-parent="{ row }">{{ row.parent ?? t('Parent') }}</template>
        <template #cell-active="{ row }">
            <Badge :tone="row.active ? 'success' : 'neutral'">{{ row.active ? t('Active') : t('Inactive') }}</Badge>
        </template>
        <template #cell-locales="{ row }">
            <span class="text-muted uppercase">{{ row.locales.join(', ') }}</span>
        </template>
        <template #empty>
            <Button v-if="can('Category Create')" variant="primary" :href="route('categories.add')">{{ t('Add Category') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Category"
                routes="categories"
                :id="row.id"
                :title="row.name"
                :edit-href="route('categories.edit', row.id)"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Slug'), value: row.slug },
                    { label: t('Code'), value: row.code },
                    { label: t('Parent Category'), value: row.parent ?? t('Parent') },
                    { label: t('Description'), value: row.description },
                ]"
                @delete="remove(row)"
            />
        </template>
        <template #bulk="{ selected }">
            <Button variant="danger" size="sm" @click="removeMany(selected)">{{ t('Delete') }}</Button>
        </template>
    </DataTable>
</template>
