<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';

import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { can, t } from '@/lib/i18n';
import { ExternalLink, ImagePlus, ShoppingBag, ShoppingCart } from '@/lib/icons';
import { route } from '@/lib/route';

import ItemLevelFigures from '../../Inventory/parts/item-card/ItemLevelFigures.vue';
import OpenOrdersTable from '../../Inventory/parts/item-card/OpenOrdersTable.vue';
import type { ItemDetails } from './types';
import { gridKey } from './useItemsGrid';

/**
 * The item's details while filling a document (a sub-item's barcode or the stock strip opens it):
 * what it is, its stock in all warehouses, what open sales orders hold and what open purchase
 * orders bring in, so a customer can be told whether more is on the way. Same data as the item
 * stock card (ItemCardData), which it links to.
 */
const props = defineProps<{ warehouseId?: number | string | null }>();

const grid = inject(gridKey)!;

const details = ref<ItemDetails | null>(null);
const loading = ref(false);
const failed = ref(false);

const open = computed({
    get: () => grid.detailsProductId.value !== null,
    set: (value: boolean) => {
        if (!value) grid.detailsProductId.value = null;
    },
});

watch(grid.detailsProductId, async (id) => {
    if (id === null) return;

    details.value = null;
    failed.value = false;
    loading.value = true;

    try {
        const data = await grid.api.details(id);

        if (grid.detailsProductId.value === id) details.value = data;
    } catch {
        if (grid.detailsProductId.value === id) failed.value = true;
    } finally {
        if (grid.detailsProductId.value === id) loading.value = false;
    }
});

const facts = computed(() => {
    const product = details.value?.product;

    return product
        ? [
              [t('Price Group'), product.price_group],
              [t('Barcode'), product.barcode],
              [t('Code'), product.sku],
              [t('Weight per Meter'), product.weight_per_meter],
              [t('Length'), product.length],
              [t('Shelf No'), product.shelf],
          ].filter(([, value]) => !!value)
        : [];
});

const isHere = (warehouseId: number): boolean => !!props.warehouseId && Number(props.warehouseId) === warehouseId;
</script>

<template>
    <Modal v-model:open="open" :title="details?.product.name ?? t('Item Details')" :description="t('Stock in all warehouses and open orders.')" width="max-w-4xl">
        <div v-if="loading" class="flex items-center justify-center gap-2 py-16 text-sm text-muted">
            <span class="size-4 animate-spin rounded-full border-2 border-current border-e-transparent" aria-hidden="true" />{{ t('Loading...') }}
        </div>
        <p v-else-if="failed || !details" class="py-16 text-center text-sm text-error-600">{{ t('The item details could not be loaded.') }}</p>

        <div v-else class="grid gap-4">
            <!-- The item -->
            <div class="flex flex-col gap-4 sm:flex-row">
                <div class="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-line bg-gray-50 dark:bg-white/[0.02]">
                    <img v-if="details.product.image" :src="details.product.image" :alt="details.product.name" class="max-h-full w-full object-contain" />
                    <ImagePlus v-else class="size-8 text-gray-300 dark:text-gray-600" />
                </div>
                <div class="min-w-0 flex-1">
                    <dl class="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                        <div v-for="[label, value] in facts" :key="String(label)" class="flex gap-2">
                            <dt class="text-muted">{{ label }}:</dt>
                            <dd class="font-semibold text-gray-900 dark:text-white"><bdi>{{ value }}</bdi></dd>
                        </div>
                    </dl>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <Badge :tone="details.card.status.tone" dot>{{ details.card.status.label }}</Badge>
                        <span v-if="details.card.levels.weight" class="text-muted">
                            {{ t('Weight') }}: <b class="text-gray-800 tabular-nums dark:text-gray-100" dir="ltr">{{ details.card.levels.weight }}</b> {{ t('kg') }}
                        </span>
                        <span v-if="details.card.main" class="text-muted">· {{ t('Colours') }}: <b class="tabular-nums" dir="ltr">{{ details.card.main.sub_items }}</b></span>
                    </div>
                </div>
            </div>

            <ItemLevelFigures :levels="details.card.levels" :status="details.card.status" class="overflow-hidden rounded-lg border border-line" />

            <!-- Open orders: incoming first, it is what the customer asks about -->
            <div class="grid gap-4 md:grid-cols-2">
                <section class="rounded-lg border border-blue-light-200 bg-blue-light-25 p-3 dark:border-blue-light-500/25 dark:bg-blue-light-500/[0.04]">
                    <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                        <ShoppingCart class="size-4 text-blue-light-600" />{{ t('Open Purchase Orders (incoming)') }}
                    </h3>
                    <OpenOrdersTable :rows="details.card.purchase_orders" type="purchase" new-tab />
                </section>
                <section class="rounded-lg border border-line p-3">
                    <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                        <ShoppingBag class="size-4 text-warning-600" />{{ t('Open Sales Orders (reserved)') }}
                    </h3>
                    <OpenOrdersTable :rows="details.card.sales_orders" type="sales" new-tab />
                </section>
            </div>

            <section v-if="details.card.warehouses.length" class="rounded-lg border border-line p-3">
                <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">{{ t('Stock by Warehouse') }}</h3>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="row in details.card.warehouses" :key="row.id" class="border-b border-line last:border-0" :class="isHere(row.id) ? 'font-semibold text-brand-700 dark:text-brand-300' : ''">
                            <td class="py-1.5">{{ row.name }}<span v-if="isHere(row.id)" class="ms-2 text-xs font-normal text-muted">({{ t('This document') }})</span></td>
                            <td class="py-1.5 text-end tabular-nums" dir="ltr">{{ row.quantity }}</td>
                            <td class="py-1.5 text-end text-xs text-muted tabular-nums" dir="ltr">{{ row.weight ? `${row.weight} ${t('kg')}` : '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <template #footer>
            <Button
                v-if="details && can('Stock Item Card View')"
                size="sm"
                external
                new-tab
                :href="route('accounting.inventory.item-card', { product: details.product.id })"
            >
                <ExternalLink />{{ t('Open Item Stock Card') }}
            </Button>
            <Button size="sm" @click="open = false">{{ t('Close') }}</Button>
        </template>
    </Modal>
</template>
