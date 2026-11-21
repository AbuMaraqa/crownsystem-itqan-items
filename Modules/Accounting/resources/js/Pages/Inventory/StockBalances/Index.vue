<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import DataTable from '@/Components/table/DataTable.vue';
import ActionButton from '@/Components/table/ActionButton.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { CheckRead, Eye, Printer, QrCode } from '@/lib/icons';
import { route } from '@/lib/route';
import type { TableColumn, TableFilter, TablePayload } from '@/types/table';

import type { Option, StatusBadge } from '../parts/types';

defineOptions({ layout: AdminLayout });

/** A row of StockBalancesService::rows(): figures already formatted by the server. */
interface BalanceRow {
    id: number;
    image: string | null;
    barcode: string | null;
    name: string;
    detail: string | null;
    is_main: boolean;
    location: string | null;
    on_hand: string;
    on_hand_tone: StatusBadge['tone'];
    weight: string;
    reserved: string;
    incoming: string;
    available: string;
    available_negative: boolean;
    limits: string;
    max_limit: string;
    min_limit_1: string;
    min_limit_2: string;
    status: StatusBadge;
    status_hint: string;
    average_cost: string | null;
    stock_value: string | null;
}

const props = defineProps<{
    table: TablePayload<BalanceRow>;
    warehouseId: number | null;
    mainProduct: Option | null;
    warehouseOptions: Option[];
    statusOptions: Option[];
    canSeeCost: boolean;
    printUrl: string;
}>();

const tones: Record<string, string> = {
    neutral: '',
    success: 'text-success-600 dark:text-success-400',
    warning: 'text-warning-600 dark:text-warning-400',
    danger: 'text-error-600 dark:text-error-400',
    info: 'text-blue-light-600 dark:text-blue-light-400',
    brand: 'text-brand-600 dark:text-brand-400',
};

const columns = computed<TableColumn[]>(() => [
    { key: 'image', label: t('Section'), toggleable: true },
    { key: 'barcode', label: t('Barcode'), sort: 'barcode' },
    { key: 'name', label: t('Item') },
    ...(props.warehouseId ? [{ key: 'location', label: t('Location') }] : []),
    { key: 'on_hand', label: t('On Hand'), sort: 'stock_on_hand', class: 'tabular' },
    { key: 'weight', label: t('Weight (kg)'), sort: 'stock_weight', class: 'tabular', toggleable: true },
    { key: 'reserved', label: t('Sales Orders Qty'), class: 'tabular' },
    { key: 'incoming', label: t('Purchase Orders Qty'), class: 'tabular' },
    { key: 'available', label: t('Available'), class: 'tabular' },
    { key: 'limits', label: t('Limits (Max / Min 1 / Min 2)') },
    { key: 'max_limit', label: t('Max Limit'), class: 'tabular', toggleable: true, hidden: true },
    { key: 'min_limit_1', label: t('Min Limit 1'), class: 'tabular', toggleable: true, hidden: true },
    { key: 'min_limit_2', label: t('Min Limit 2'), class: 'tabular', toggleable: true, hidden: true },
    { key: 'status', label: t('Status') },
    ...(props.canSeeCost
        ? [
              { key: 'average_cost', label: t('Average Cost'), class: 'tabular', toggleable: true, hidden: true },
              { key: 'stock_value', label: t('Stock Value'), class: 'tabular', toggleable: true, hidden: true },
          ]
        : []),
]);

const filters = computed<TableFilter[]>(() => [
    { key: 'warehouse', label: t('Warehouse'), options: props.warehouseOptions },
    // The aluminium type is chosen above the table (a long list); its chip shows and clears it here.
    { key: 'main_product', label: t('Aluminum Type'), options: props.mainProduct ? [props.mainProduct] : [] },
    { key: 'status', label: t('Status'), options: props.statusOptions },
    { type: 'boolean', key: 'main_items', label: t('Main items only (aluminum types)') },
    { type: 'boolean', key: 'with_stock', label: t('Only items with stock or movements'), trueLabel: t('Yes'), falseLabel: t('All items') },
]);

const mainProductId = ref<string | number | null>(props.mainProduct?.value ?? null);

/** A new aluminium type reloads the page with it, keeping the other filters. */
function chooseMainProduct(value: string | number | null | undefined): void {
    mainProductId.value = value ?? null;
    const filter = { ...props.table.query.filter, main_product: value ?? undefined };

    router.get(window.location.pathname, { filter, sort: props.table.query.sort }, { preserveScroll: true });
}

const cardUrl = (row: BalanceRow): string => route('accounting.inventory.item-card', { product: row.id, warehouse: props.warehouseId ?? undefined });

const countUrl = computed(() => route('accounting.inventory.adjustments.add', { type: 'count', warehouse: props.warehouseId ?? undefined }));

function labelsUrl(ids: (number | string)[]): string {
    return route('accounting.inventory.print.shelf-labels', { products: ids.join(','), warehouse: props.warehouseId ?? undefined });
}
</script>

<template>
    <PageHeader :title="t('Stock Balances')" :description="t('On hand, reserved for open sales orders and incoming from open purchase orders.')" :count="table.meta.total">
        <template #actions>
            <Button external new-tab :href="printUrl"><Printer />{{ t('Print Stock Report') }}</Button>
            <Button v-if="can('Stock Adjustment Create')" variant="primary" :href="countUrl"><CheckRead />{{ t('New Stock Count') }}</Button>
        </template>
    </PageHeader>

    <Card class="mb-4">
        <div class="grid gap-4 md:grid-cols-3">
            <RemoteSelect
                :model-value="mainProductId"
                :url="route('accounting.inventory.stock.main-products')"
                :initial="mainProduct ? [mainProduct] : []"
                :label="t('Aluminum Type')"
                :placeholder="t('All')"
                @update:model-value="chooseMainProduct"
            />
        </div>
    </Card>

    <DataTable :key="String(mainProduct?.value ?? '')" :table="table" :columns="columns" :filters="filters" :selectable="can('Shelf Label Print')" :empty-title="t('No items found')">
        <template #bulk="{ selected }">
            <Button size="sm" external new-tab :href="labelsUrl(selected)"><QrCode />{{ t('Print Shelf Labels') }}</Button>
        </template>
        <template #cell-image="{ row }">
            <img v-if="row.image" :src="row.image" alt="" class="h-9 w-12 rounded border border-line object-contain" />
            <span v-else class="text-muted">-</span>
        </template>
        <template #cell-barcode="{ row }"><bdi class="font-mono font-semibold" dir="ltr">{{ row.barcode ?? '-' }}</bdi></template>
        <template #cell-name="{ row }">
            <div class="min-w-56">
                <Link v-if="can('Stock Item Card View')" :href="cardUrl(row)" class="font-medium text-gray-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ row.name }}</Link>
                <span v-else class="font-medium">{{ row.name }}</span>
                <div v-if="row.detail" class="text-xs text-muted">{{ row.detail }}</div>
            </div>
        </template>
        <template #cell-location="{ row }"><bdi class="font-mono text-xs" dir="ltr">{{ row.location ?? '-' }}</bdi></template>
        <template #cell-on_hand="{ row }"><b class="tabular-nums" :class="tones[row.on_hand_tone]" dir="ltr">{{ row.on_hand }}</b></template>
        <template #cell-weight="{ row }"><span class="tabular-nums" :class="row.is_main ? 'font-semibold' : ''" dir="ltr">{{ row.weight }}</span></template>
        <template #cell-reserved="{ row }"><span class="tabular-nums text-warning-600 dark:text-warning-400" dir="ltr">{{ row.reserved }}</span></template>
        <template #cell-incoming="{ row }"><span class="tabular-nums text-blue-light-600 dark:text-blue-light-400" dir="ltr">{{ row.incoming }}</span></template>
        <template #cell-available="{ row }"><span class="tabular-nums" :class="row.available_negative ? 'text-error-600' : ''" dir="ltr">{{ row.available }}</span></template>
        <template #cell-limits="{ row }"><span class="text-muted tabular-nums" dir="ltr">{{ row.limits }}</span></template>
        <template #cell-status="{ row }">
            <span :title="row.status_hint"><Badge :tone="row.status.tone" dot>{{ row.status.label }}</Badge></span>
        </template>
        <template #cell-average_cost="{ row }"><span class="tabular-nums" dir="ltr">{{ row.average_cost ?? '-' }}</span></template>
        <template #cell-stock_value="{ row }"><span class="tabular-nums" dir="ltr">{{ row.stock_value ?? '-' }}</span></template>
        <template #actions="{ row }">
            <!-- A balance row is a sum of movements, not a record: no info, edit or delete; View opens the item card. -->
            <ActionButton v-if="can('Stock Item Card View')" :label="t('Item Card')" tone="view" :href="cardUrl(row)"><Eye /></ActionButton>
        </template>
    </DataTable>
</template>
