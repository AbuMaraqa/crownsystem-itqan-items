<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { ArrowUpDown, ImagePlus, MapPin, Pencil, QrCode, Returns, ShoppingBag, ShoppingCart, X } from '@/lib/icons';
import { route } from '@/lib/route';

import { tableClasses as c } from '../../Report/parts/types';
import { useReportFilters } from '../../Report/parts/useReportFilters';
import ItemLevelFigures from '../parts/item-card/ItemLevelFigures.vue';
import OpenOrdersTable from '../parts/item-card/OpenOrdersTable.vue';
import type { ItemCardProduct, ItemCardSummary } from '../parts/item-card/types';
import SearchPicker from '../parts/SearchPicker.vue';
import type { Option, StatusBadge } from '../parts/types';

defineOptions({ layout: AdminLayout });

/** ItemStockCardController::card(): ItemCardData::summary() plus the card page's own parts. */
interface CardData extends ItemCardSummary {
    sub_items: { id: number; code: string | null; name: string; on_hand: string; weight: string; reserved: string; incoming: string; available: string; available_negative: boolean; value: string | null }[];
    placements: { id: number; code: string | null; is_primary: boolean; arrow: string | null; warehouse: string | null; quantity: string | null }[];
    movements: {
        opening: string;
        rows: { id: number; date: string | null; type: StatusBadge | null; reference_no: string | null; url: string | null; warehouse: string | null; location: string | null; in: string | null; out: string | null; balance: string; unit_cost: string | null }[];
    };
}

const props = defineProps<{
    filters: { warehouse: number | null; from: string | null; to: string | null };
    warehouseOptions: Option[];
    product: ItemCardProduct | null;
    card: CardData | null;
    locationOptions: Option[];
    directionOptions: Option[];
    canSeeCost: boolean;
}>();

// «All warehouses» and «no start date» are real choices, so they are sent empty.
const { filters } = useReportFilters(
    { warehouse: props.filters.warehouse as number | string | null, from: props.filters.from, to: props.filters.to },
    { keepEmpty: ['warehouse', 'from'] },
);

function open(option: Option): void {
    router.get(route('accounting.inventory.item-card', { product: option.value }), { warehouse: filters.warehouse ?? '' });
}

const placement = useForm<{ location_id: number | string | null; direction: string | null; is_primary: boolean }>({ location_id: null, direction: null, is_primary: true });

function assign(): void {
    if (!props.product) return;

    placement.post(route('accounting.inventory.item-card.locations.assign', props.product.id), {
        preserveScroll: true,
        onSuccess: () => placement.reset(),
    });
}

function makePrimary(id: number): void {
    if (props.product) router.post(route('accounting.inventory.item-card.locations.primary', [props.product.id, id]), {}, { preserveScroll: true });
}

async function removePlacement(id: number): Promise<void> {
    if (props.product && (await confirm({ title: t('Remove this location from the item?'), confirmLabel: t('Remove') }))) {
        router.delete(route('accounting.inventory.item-card.locations.remove', [props.product.id, id]), { preserveScroll: true });
    }
}

const details = computed(() =>
    props.product
        ? [
              [t('Aluminum Type'), props.product.main_product],
              [t('Profile Name'), props.product.name],
              [t('Barcode'), props.product.barcode],
              [t('Code'), props.product.sku],
              [t('Weight per Meter'), props.product.weight_per_meter],
              [t('Length'), props.product.length],
          ].filter(([, value]) => !!value)
        : [],
);

const cardUrl = (id: number): string => route('accounting.inventory.item-card', { product: id, warehouse: filters.warehouse || undefined });
const orderBlocks = computed(() =>
    props.card
        ? [
              { title: t('Open Sales Orders (reserved)'), rows: props.card.sales_orders, icon: ShoppingBag, type: 'sales' as const },
              { title: t('Open Purchase Orders (incoming)'), rows: props.card.purchase_orders, icon: ShoppingCart, type: 'purchase' as const },
          ]
        : [],
);
</script>

<template>
    <PageHeader :title="product ? `${t('Item Stock Card')}: ${product.name}` : t('Item Stock Card')" />

    <div class="grid gap-4">
        <Card>
            <div class="grid gap-4 md:grid-cols-[1fr_16rem] md:items-end">
                <SearchPicker :url="route('accounting.inventory.item-card.search')" :label="t('Item')" :placeholder="t('Search item by name or scan barcode')" scanner @pick="open" />
                <SelectInput v-model="filters.warehouse" :label="t('Warehouse')" :options="warehouseOptions" :placeholder="t('All warehouses')" />
            </div>
        </Card>

        <div v-if="!product || !card" class="rounded-xl border border-dashed border-line bg-card px-6 py-16 text-center text-muted">
            <QrCode class="mx-auto mb-3 size-12 text-gray-300 dark:text-gray-600" />
            {{ t('Scan the shelf label QR code or the item barcode to open its card.') }}
        </div>

        <template v-else>
            <div class="grid gap-4 xl:grid-cols-3">
                <!-- The card -->
                <div class="overflow-hidden rounded-xl border border-line bg-card shadow-card xl:col-span-2">
                    <div class="flex flex-col md:flex-row">
                        <div class="flex items-center justify-center border-b border-line bg-gray-50 p-4 md:w-64 md:border-e md:border-b-0 dark:bg-white/[0.02]">
                            <img v-if="product.image" :src="product.image" :alt="product.name" class="max-h-48 w-full object-contain" />
                            <div v-else class="text-center text-xs text-muted"><ImagePlus class="mx-auto mb-1 size-10" />{{ t('No section image') }}</div>
                        </div>
                        <dl class="flex-1 divide-y divide-line text-sm">
                            <div v-for="[label, value] in details" :key="String(label)" class="grid grid-cols-3 gap-2 px-4 py-2">
                                <dt class="text-muted">{{ label }}</dt>
                                <dd class="col-span-2 font-semibold text-gray-900 dark:text-white"><bdi>{{ value }}</bdi></dd>
                            </div>
                            <div class="grid grid-cols-3 gap-2 px-4 py-2">
                                <dt class="text-muted">{{ t('Shelf No') }}</dt>
                                <dd class="col-span-2 flex flex-wrap items-center gap-1">
                                    <span
                                        v-for="place in card.placements"
                                        :key="place.id"
                                        class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 font-mono text-sm font-bold"
                                        :class="place.is_primary ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200'"
                                        dir="ltr"
                                    >
                                        {{ place.code }} {{ place.arrow ?? '' }}
                                        <span v-if="!filters.warehouse" class="font-sans text-[10px] font-normal opacity-75">{{ place.warehouse }}</span>
                                        <span v-if="place.quantity !== null" class="text-[11px] font-normal opacity-80">({{ place.quantity }})</span>
                                    </span>
                                    <span v-if="!card.placements.length" class="font-mono font-semibold">{{ product.shelf || '—' }}</span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <ItemLevelFigures :levels="card.levels" :status="card.status" :limits="product" class="border-t border-line" />

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <Badge :tone="card.status.tone" dot>{{ card.status.label }}</Badge>
                            <span v-if="card.levels.weight" class="text-muted">{{ t('Weight') }}: <b class="text-gray-800 tabular-nums dark:text-gray-100" dir="ltr">{{ card.levels.weight }}</b> {{ t('kg') }}</span>
                            <span v-if="card.value" class="text-muted">· {{ t('Stock Value') }}: <b class="text-gray-800 tabular-nums dark:text-gray-100" dir="ltr">{{ card.value }}</b></span>
                            <template v-else-if="card.cost">
                                <span v-if="card.cost.per_kg" class="text-muted">· {{ t('Cost per Kg') }}: <b class="text-gray-800 tabular-nums dark:text-gray-100" dir="ltr">{{ card.cost.per_kg }}</b></span>
                                <span class="text-muted">· {{ card.cost.per_kg ? t('Cost per Piece') : t('Average Cost') }}: <b class="text-gray-800 tabular-nums dark:text-gray-100" dir="ltr">{{ card.cost.average }}</b></span>
                                <span class="text-muted">· {{ t('Stock Value') }}: <b class="text-gray-800 tabular-nums dark:text-gray-100" dir="ltr">{{ card.cost.value }}</b></span>
                            </template>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="can('Shelf Label Print')"
                                size="sm"
                                external
                                new-tab
                                :href="route('accounting.inventory.print.shelf-labels', { products: product.id, warehouse: filters.warehouse || undefined })"
                            >
                                <QrCode />{{ t('Shelf Label') }}
                            </Button>
                            <Button v-if="can('Stock Transfer Create')" size="sm" :href="route('accounting.inventory.transfers.add', { product: product.id, warehouse: filters.warehouse || undefined })">
                                <Returns />{{ t('Transfer') }}
                            </Button>
                            <Button v-if="can('Product Update')" size="sm" :href="route('products.edit', product.id)"><Pencil />{{ t('Edit Item') }}</Button>
                        </div>
                    </div>
                </div>

                <!-- Warehouses and locations -->
                <div class="grid content-start gap-4">
                    <Card :title="t('Stock by Warehouse')">
                        <table class="-m-1 w-full text-sm">
                            <tbody>
                                <tr v-for="(row, index) in card.warehouses" :key="index" class="border-b border-line last:border-0">
                                    <td class="py-2">{{ row.name }}</td>
                                    <td class="py-2 text-end font-semibold tabular-nums" dir="ltr">{{ row.quantity }}</td>
                                    <td class="py-2 text-end text-xs text-muted tabular-nums" dir="ltr">{{ row.weight ? `${row.weight} ${t('kg')}` : '' }}</td>
                                </tr>
                                <tr v-if="!card.warehouses.length"><td class="py-6 text-center text-muted">{{ t('No stock yet') }}</td></tr>
                            </tbody>
                        </table>
                    </Card>

                    <Card :title="t('Locations')">
                        <ul class="-m-1 divide-y divide-line text-sm">
                            <li v-for="place in card.placements" :key="place.id" class="flex items-center justify-between gap-2 py-2">
                                <span>
                                    <bdi class="font-mono font-bold" dir="ltr">{{ place.code }} {{ place.arrow ?? '' }}</bdi>
                                    <span class="ms-1 text-xs text-muted">{{ place.is_primary ? t('Primary') : t('Overflow') }} · {{ place.warehouse }}</span>
                                </span>
                                <span v-if="can('Warehouse Location Manage')" class="flex items-center gap-2">
                                    <button v-if="!place.is_primary" type="button" class="text-xs text-brand-600 hover:underline dark:text-brand-400" @click="makePrimary(place.id)">{{ t('Make Primary') }}</button>
                                    <button type="button" class="text-error-500 hover:text-error-600" :aria-label="t('Remove')" @click="removePlacement(place.id)"><X class="size-4" /></button>
                                </span>
                            </li>
                            <li v-if="!card.placements.length" class="py-4 text-center text-muted">{{ t('No location assigned yet') }}</li>
                        </ul>

                        <form v-if="can('Warehouse Location Manage') && filters.warehouse" class="mt-4 grid gap-3 border-t border-line pt-4" @submit.prevent="assign">
                            <div class="grid grid-cols-2 gap-2">
                                <SelectInput v-model="placement.location_id" :options="locationOptions" :placeholder="t('Location')" :error="placement.errors.location_id" />
                                <SelectInput v-model="placement.direction" :options="directionOptions" :placeholder="t('Arrow direction')" :error="placement.errors.direction" />
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <Toggle v-model="placement.is_primary" :label="t('Primary location')" />
                                <Button type="submit" variant="primary" size="sm" :loading="placement.processing"><MapPin />{{ t('Assign') }}</Button>
                            </div>
                        </form>
                        <p v-else-if="can('Warehouse Location Manage')" class="mt-3 border-t border-line pt-3 text-xs text-muted">{{ t('Select a warehouse to assign locations.') }}</p>
                    </Card>
                </div>
            </div>

            <!-- A main item: its balance is the total of its sub-items -->
            <Card v-if="card.main" :title="t('Sub Items')" :description="t('The main item holds no stock of its own; its balance is the total of its sub-items.')">
                <div class="-m-5 overflow-x-auto">
                    <table :class="[c.table, 'min-w-[48rem]']">
                        <thead :class="c.head">
                            <tr class="border-b border-line">
                                <th :class="[c.th, 'text-start']">{{ t('Barcode') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Item') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('On Hand') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Weight (kg)') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Sales Orders Qty') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Purchase Orders Qty') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Available') }}</th>
                                <th v-if="canSeeCost" :class="[c.th, 'text-end']">{{ t('Stock Value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in card.sub_items" :key="row.id" :class="c.row">
                                <td :class="c.td"><Link :href="cardUrl(row.id)" :class="[c.link, 'font-mono font-semibold']" dir="ltr">{{ row.code }}</Link></td>
                                <td :class="c.td">{{ row.name }}</td>
                                <td :class="c.number" dir="ltr">{{ row.on_hand }}</td>
                                <td :class="c.number" dir="ltr">{{ row.weight }}</td>
                                <td :class="[c.number, 'text-warning-600']" dir="ltr">{{ row.reserved }}</td>
                                <td :class="[c.number, 'text-blue-light-600']" dir="ltr">{{ row.incoming }}</td>
                                <td :class="[c.number, row.available_negative ? 'text-error-600' : '']" dir="ltr">{{ row.available }}</td>
                                <td v-if="canSeeCost" :class="c.number" dir="ltr">{{ row.value }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr :class="c.foot">
                                <td colspan="2" :class="c.td">{{ t('Total') }} ({{ card.main.sub_items }})</td>
                                <td :class="c.number" dir="ltr">{{ card.main.on_hand }}</td>
                                <td :class="[c.number, 'text-brand-600 dark:text-brand-400']" dir="ltr">{{ card.main.weight }}</td>
                                <td :class="c.number" dir="ltr">{{ card.main.reserved }}</td>
                                <td :class="c.number" dir="ltr">{{ card.main.incoming }}</td>
                                <td :class="c.number" dir="ltr">{{ card.main.available }}</td>
                                <td v-if="canSeeCost" :class="c.number" dir="ltr">{{ card.main.value }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Card>

            <!-- Open orders -->
            <div class="grid gap-4 lg:grid-cols-2">
                <Card v-for="block in orderBlocks" :key="block.title">
                    <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white"><component :is="block.icon" class="size-4 text-brand-500" />{{ block.title }}</h3>
                    <OpenOrdersTable :rows="block.rows" :type="block.type" />
                </Card>
            </div>

            <!-- Movements -->
            <Card>
                <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white"><ArrowUpDown class="size-4 text-brand-500" />{{ t('Item Movements') }}</h3>
                    <div class="flex items-end gap-2">
                        <TextInput v-model="filters.from" type="date" :label="t('From Date')" />
                        <TextInput v-model="filters.to" type="date" :label="t('To Date')" />
                    </div>
                </div>
                <div class="-mx-5 -mb-5 overflow-x-auto">
                    <table :class="[c.table, 'min-w-[56rem]']">
                        <thead :class="c.head">
                            <tr class="border-b border-line">
                                <th :class="[c.th, 'text-start']">{{ t('Date') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Movement') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Reference No') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Warehouse') }}</th>
                                <th :class="[c.th, 'text-start']">{{ t('Location') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('In') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Out') }}</th>
                                <th :class="[c.th, 'text-end']">{{ t('Balance') }}</th>
                                <th v-if="canSeeCost" :class="[c.th, 'text-end']">{{ t('Unit Cost') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="movement in card.movements.rows" :key="movement.id" :class="c.row">
                                <td :class="[c.td, 'whitespace-nowrap tabular-nums']" dir="ltr">{{ movement.date }}</td>
                                <td :class="c.td"><Badge v-if="movement.type" :tone="movement.type.tone">{{ movement.type.label }}</Badge></td>
                                <td :class="[c.td, 'font-mono text-xs']">
                                    <a v-if="movement.url" :href="movement.url" :class="c.link" dir="ltr">{{ movement.reference_no }}</a>
                                    <bdi v-else dir="ltr">{{ movement.reference_no }}</bdi>
                                </td>
                                <td :class="c.td">{{ movement.warehouse }}</td>
                                <td :class="[c.td, 'font-mono text-xs']" dir="ltr">{{ movement.location ?? '—' }}</td>
                                <td :class="[c.number, 'text-success-600']" dir="ltr">{{ movement.in ?? '' }}</td>
                                <td :class="[c.number, 'text-error-600']" dir="ltr">{{ movement.out ?? '' }}</td>
                                <td :class="[c.number, 'font-semibold']" dir="ltr">{{ movement.balance }}</td>
                                <td v-if="canSeeCost" :class="[c.number, 'text-muted']" dir="ltr">{{ movement.unit_cost }}</td>
                            </tr>
                            <tr v-if="!card.movements.rows.length">
                                <td :colspan="canSeeCost ? 9 : 8" :class="c.empty">{{ t('No movements in this period') }}</td>
                            </tr>
                            <tr v-if="filters.from" class="bg-gray-50 dark:bg-white/[0.02]">
                                <td colspan="7" :class="[c.td, 'text-xs font-semibold text-muted']">{{ t('Opening balance before') }} <span dir="ltr">{{ filters.from }}</span></td>
                                <td :class="[c.number, 'font-semibold']" dir="ltr">{{ card.movements.opening }}</td>
                                <td v-if="canSeeCost" />
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </template>
    </div>
</template>
