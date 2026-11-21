<script setup lang="ts">
/**
 * The items list: main items only, each sub-item listed inside its main item. The search goes through
 * ProductSearch (every field, closest first) and also finds a main item by its sub-item.
 */
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import DataTable from '@/Components/table/DataTable.vue';
import Button from '@/Components/ui/Button.vue';
import DropdownItem from '@/Components/ui/DropdownItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { DollarSign, QrCode, Tag } from '@/lib/icons';
import { route } from '@/lib/route';
import { createdByColumn } from '@/lib/tableColumns';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import RecordActions from '@/Components/table/RecordActions.vue';
import { useRecordDelete } from '@/lib/useRecordDelete';
import BarcodeModal from './parts/BarcodeModal.vue';

defineOptions({ layout: AdminLayout });

interface SubItem {
    id: number;
    /** Escaped by the server; the search match is wrapped in <mark>. */
    name_html: string;
    barcode: string | null;
    matched: boolean;
}

interface Row {
    id: number;
    image: string | null;
    sku: string;
    barcode: string | null;
    name: string;
    slug: string;
    description: string | null;
    categories: string;
    is_active: boolean;
    pricing_type: string;
    base_price: string | number;
    locales: string[];
    sub_items: { count: number; rows: SubItem[]; more: number } | null;
}

const props = defineProps<{ table: TablePayload<Row>; categories: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'image', label: t('Image'), toggleable: true, hidden: true },
    { key: 'sku', label: t('Item Number'), sort: 'sku', toggleable: true },
    { key: 'barcode', label: t('Barcode'), toggleable: true, hidden: true },
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'sub_items', label: t('Sub Items') },
    { key: 'slug', label: t('Slug'), sort: 'slug', toggleable: true },
    { key: 'categories', label: t('Category'), toggleable: true },
    { key: 'is_active', label: t('Active'), sort: 'is_active' },
    { key: 'base_price', label: t('Base Price'), sort: 'base_price', class: 'tabular', toggleable: true },
    { key: 'locales', label: t('Locale'), toggleable: true },
    createdByColumn(),
]);

const filters = computed<TableFilter[]>(() => [
    { type: 'text', key: 'name', label: t('Name'), placeholder: t('Search by name') },
    { key: 'category_id', label: t('Category'), options: props.categories },
    {
        key: 'pricing_type',
        label: t('Pricing Type'),
        options: [
            { value: 'simple', label: t('By Quantity') },
            { value: 'weight', label: t('By Weight') },
        ],
    },
    {
        key: 'is_active',
        label: t('Active'),
        options: [
            { value: 1, label: t('Active') },
            { value: 0, label: t('Inactive') },
        ],
    },
    { type: 'number-range', key: 'base_price', label: t('Base Price') },
    { type: 'date-range', key: 'created_at', label: t('Created at') },
]);

function setActive(row: Row, active: boolean): void {
    router.put(route('products.active', row.id), { active }, { preserveScroll: true, only: ['table', 'flash'] });
}

const barcodeOpen = ref(false);
const barcodeRow = ref<Row | null>(null);

function openBarcodes(row: Row): void {
    barcodeRow.value = row;
    barcodeOpen.value = true;
}

const { remove, removeMany } = useRecordDelete('products');
</script>

<template>
    <PageHeader :title="t('Products List')" :count="table.meta.total">
        <template #actions>
            <Button v-if="can('Product Create')" variant="primary" :href="route('products.add')">{{ t('Add Product') }}</Button>
        </template>
    </PageHeader>

    <DataTable
        :table="table"
        :columns="columns"
        :filters="filters"
        :heading="t('Products')"
        :selectable="can('Product Delete')"
        :empty-title="t('No products found')"
        :empty-text="t('Create a new product by clicking the button below')"
    >
        <template #cell-image="{ row }">
            <img v-if="row.image" :src="row.image" alt="" class="size-12 rounded-control object-cover" />
        </template>
        <template #cell-sku="{ row }">
            <span class="font-mono" dir="ltr">{{ row.sku }}</span>
        </template>
        <template #cell-barcode="{ row }">
            <span class="font-mono" dir="ltr">{{ row.barcode || '—' }}</span>
        </template>
        <template #cell-name="{ row }">
            <Link v-if="can('Product Update')" :href="route('products.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ row.name }}</Link>
            <template v-else>{{ row.name }}</template>
        </template>
        <template #cell-sub_items="{ row }">
            <div v-if="row.sub_items" class="min-w-56 text-sm">
                <span class="inline-flex rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                    {{ t(':count sub-items', { count: row.sub_items.count }) }}
                </span>
                <div class="mt-1 border-s-2 border-brand-200 ps-2 dark:border-brand-500/30">
                    <component
                        :is="can('Product Update') ? Link : 'span'"
                        v-for="sub in row.sub_items.rows"
                        :key="sub.id"
                        :href="can('Product Update') ? route('products.edit', sub.id) : undefined"
                        class="flex items-center gap-1.5 py-0.5 hover:underline"
                        :class="sub.matched ? 'font-semibold text-brand-600 dark:text-brand-400' : 'text-muted'"
                    >
                        <span class="text-gray-300 dark:text-gray-600">└</span>
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <span v-html="sub.name_html" />
                        <span v-if="sub.barcode" class="font-mono text-xs text-gray-400" dir="ltr">{{ sub.barcode }}</span>
                    </component>
                    <Link v-if="row.sub_items.more > 0 && can('Product Update')" :href="route('products.edit', row.id)" class="block py-0.5 ps-4 text-xs text-brand-600 hover:underline dark:text-brand-400">
                        {{ t('+ :count more sub-items', { count: row.sub_items.more }) }}
                    </Link>
                </div>
            </div>
            <span v-else class="text-muted">—</span>
        </template>
        <template #cell-slug="{ row }">
            <span dir="ltr">{{ row.slug }}</span>
        </template>
        <template #cell-is_active="{ row }">
            <Toggle :model-value="row.is_active" :disabled="!can('Product Update')" :aria-label="t('Active')" @update:model-value="setActive(row, !!$event)" />
        </template>
        <template #cell-locales="{ row }">
            <span class="text-muted uppercase">{{ row.locales.join(', ') }}</span>
        </template>
        <template #empty>
            <Button v-if="can('Product Create')" variant="primary" :href="route('products.add')">{{ t('Add Product') }}</Button>
        </template>
        <template #actions="{ row }">
            <RecordActions
                entity="Product"
                routes="products"
                :id="row.id"
                :title="row.name"
                :edit-href="route('products.edit', row.id)"
                :fields="[
                    { label: t('Name'), value: row.name },
                    { label: t('Slug'), value: row.slug },
                    { label: t('Description'), value: row.description },
                ]"
                @delete="remove(row.id, row.name)"
            >
                <DropdownItem @select="openBarcodes(row)"><QrCode />{{ t('Generate Barcode') }}</DropdownItem>
                <DropdownItem v-if="can('Product Branch Pricings')" :href="route('products.branch-pricings', row.id)"><DollarSign />{{ t('Branch Pricings') }}</DropdownItem>
                <DropdownItem v-if="can('Product Units And Prices')" :href="route('products.units-and-prices', row.id)"><Tag />{{ t('Units, Barcodes and Prices') }}</DropdownItem>
            </RecordActions>
        </template>
        <template #bulk="{ selected }">
            <Button variant="danger" size="sm" @click="removeMany(selected)">{{ t('Delete') }}</Button>
        </template>
    </DataTable>

    <BarcodeModal v-model:open="barcodeOpen" :product-id="barcodeRow?.id ?? null" :title="barcodeRow?.name ?? ''" />
</template>
