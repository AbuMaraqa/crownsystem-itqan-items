<script setup lang="ts">
/**
 * The items list: one row per item (its variations are not rows), each with its colours. An item
 * belongs to its categories only. The search goes through ProductSearch (every field, closest first).
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

interface ColourChip {
    name: string;
    swatch: string | null;
    active: boolean;
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
    colours: { count: number; rows: ColourChip[]; more: number } | null;
}

const props = defineProps<{ table: TablePayload<Row>; categories: Option[]; finishes: Option[] }>();

const columns = computed<TableColumn[]>(() => [
    { key: 'image', label: t('Image'), toggleable: true, hidden: true },
    { key: 'sku', label: t('Item Number'), sort: 'sku', toggleable: true },
    { key: 'barcode', label: t('Barcode'), toggleable: true, hidden: true },
    { key: 'name', label: t('Name'), sort: 'name' },
    { key: 'colours', label: t('Colours'), class: 'min-w-44', toggleable: true },
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
    { key: 'finish_id', label: t('Colour'), options: props.finishes },
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
        <template #cell-colours="{ row }">
            <div v-if="row.colours" class="flex flex-wrap items-center gap-1">
                <span
                    v-for="colour in row.colours.rows"
                    :key="colour.name"
                    class="inline-flex items-center gap-1 rounded-full border border-line px-2 py-0.5 text-xs"
                    :class="{ 'opacity-50 line-through': !colour.active }"
                    :title="colour.active ? colour.name : `${colour.name} (${t('Inactive')})`"
                >
                    <span v-if="colour.swatch" class="inline-block size-2.5 rounded-full border border-line" :style="{ backgroundColor: colour.swatch }" />
                    {{ colour.name }}
                </span>
                <span v-if="row.colours.more > 0" class="text-xs text-muted">{{ t('+ :count more', { count: row.colours.more }) }}</span>
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
