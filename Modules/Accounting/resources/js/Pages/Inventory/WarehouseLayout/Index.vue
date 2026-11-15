<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { Boxes, Eye, MapPin, Package, Plus, Printer, QrCode, Trash2, X } from '@/lib/icons';
import { route } from '@/lib/route';

import SearchPicker from '../parts/SearchPicker.vue';
import type { Option } from '../parts/types';

defineOptions({ layout: AdminLayout });

interface Cell {
    id: number;
    code: string;
    active: boolean;
    items: number;
    quantity: number;
    highlight: boolean;
}

interface Zone {
    name: string;
    positions: number[];
    aisles: { aisle: string; cells: (Cell | null)[] }[];
}

interface Selected {
    id: number;
    code: string;
    zone: string | null;
    active: boolean;
    rows: { product_id: number; name: string; barcode: string | null; image: string | null; placement_id: number | null; placement: string | null; arrow: string | null; quantity: string; removable: boolean }[];
}

const props = defineProps<{
    warehouseId: number | null;
    warehouseOptions: Option[];
    directionOptions: Option[];
    highlight: string;
    map: { zones: Zone[]; stats: { locations: number; occupied: number; items: number; unassigned: number } | null };
    selected: Selected | null;
}>();

const canManage = computed(() => can('Warehouse Location Manage'));
const warehouse = ref<number | string | null>(props.warehouseId);
const highlight = ref(props.highlight);

/** The page state lives in the URL: warehouse, chosen location, item searched on the map. */
function visit(params: { location?: number | null; highlight?: string }): void {
    router.get(
        route('accounting.inventory.layout', warehouse.value ?? undefined),
        {
            location: params.location === undefined ? (props.selected?.id ?? undefined) : (params.location ?? undefined),
            highlight: (params.highlight ?? highlight.value) || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(warehouse, () => router.get(route('accounting.inventory.layout', warehouse.value ?? undefined)));

let timer: ReturnType<typeof setTimeout> | undefined;
watch(highlight, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => visit({ highlight: value }), 400);
});

function selectLocation(id: number): void {
    visit({ location: props.selected?.id === id ? null : id });
}

const generator = useForm({ zone: '', aisles: '', from: 1 as number | string, to: 12 as number | string });

function generate(): void {
    if (!props.warehouseId) return;

    generator.post(route('accounting.inventory.layout.generate', props.warehouseId), {
        preserveScroll: true,
        onSuccess: () => generator.reset('aisles'),
    });
}

async function importShelves(): Promise<void> {
    if (props.warehouseId && (await confirm({ title: t('Place all items in this warehouse according to their shelf number?'), confirmLabel: t('Import Shelf Numbers'), tone: 'primary' }))) {
        router.post(route('accounting.inventory.layout.import-shelves', props.warehouseId), {}, { preserveScroll: true });
    }
}

const placing = useForm({ product_id: null as number | string | null, direction: null as string | null, is_primary: true });

function place(option: Option): void {
    if (!props.selected) return;

    placing.product_id = option.value;
    placing.post(route('accounting.inventory.layout.place', props.selected.id), { preserveScroll: true, preserveState: true });
}

function unplace(id: number): void {
    router.delete(route('accounting.inventory.layout.unplace', id), { preserveScroll: true, preserveState: true });
}

function toggleLocation(): void {
    if (props.selected) router.post(route('accounting.inventory.layout.toggle', props.selected.id), {}, { preserveScroll: true, preserveState: true });
}

async function deleteLocation(): Promise<void> {
    if (props.selected && (await confirm({ title: t('Delete this location?'), confirmLabel: t('Delete') }))) {
        router.delete(route('accounting.inventory.layout.locations.destroy', props.selected.id), { preserveScroll: true });
    }
}

function cellClass(cell: Cell): string {
    if (cell.highlight) return 'animate-pulse border-warning-400 bg-warning-200 text-warning-900';
    if (!cell.active) return 'border-dashed border-gray-200 bg-gray-50 text-gray-300 dark:border-gray-800 dark:bg-white/5';
    if (cell.items || cell.quantity) return 'border-brand-300 bg-brand-50 text-gray-800 hover:bg-brand-100 dark:border-brand-500/40 dark:bg-brand-500/15 dark:text-gray-100';

    return 'border-gray-200 bg-card text-gray-400 hover:border-brand-300 dark:border-gray-800';
}

const stats = computed(() =>
    props.map.stats
        ? [
              { label: t('Locations'), value: props.map.stats.locations },
              { label: t('Occupied Locations'), value: props.map.stats.occupied },
              { label: t('Placed Items'), value: props.map.stats.items },
              { label: t('Stock Without Location'), value: props.map.stats.unassigned.toLocaleString('en-US', { maximumFractionDigits: 2 }) },
          ]
        : [],
);
</script>

<template>
    <PageHeader :title="t('Warehouse Layout')" :description="t('Aisles (letters on the beams) × positions (numbers on the pillars). Click a location to see and place its items.')">
        <template #actions>
            <Button v-if="can('Shelf Label Print') && warehouseId" external new-tab :href="route('accounting.inventory.print.shelf-labels', { warehouse: warehouseId })">
                <QrCode />{{ t('Print All Shelf Labels') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <Card>
            <div class="grid gap-4 md:grid-cols-[16rem_16rem]">
                <SelectInput v-model="warehouse" :label="t('Warehouse')" :options="warehouseOptions" :placeholder="t('Select a warehouse')" />
                <TextInput v-model="highlight" type="search" :label="t('Find an item on the map')" :placeholder="t('Search by name, sku or barcode.')" />
            </div>
            <div v-if="warehouseId" class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div v-for="stat in stats" :key="stat.label" class="rounded-lg border border-line bg-gray-50 px-3 py-2 dark:bg-white/[0.02]">
                    <div class="text-xs text-muted">{{ stat.label }}</div>
                    <div class="text-lg font-bold text-gray-900 tabular-nums dark:text-white" dir="ltr">{{ stat.value }}</div>
                </div>
            </div>
        </Card>

        <Card v-if="warehouseId && canManage" :title="t('Create Locations')">
            <form class="grid gap-3 md:grid-cols-6 md:items-end" @submit.prevent="generate">
                <TextInput v-model="generator.zone" :label="t('Floor / Zone')" :hint="t('Optional, e.g. Upper floor')" :error="generator.errors.zone" />
                <div class="md:col-span-2">
                    <TextInput v-model="generator.aisles" :label="t('Aisles')" :hint="t('Letters or ranges: F-T, AA-AJ, H')" placeholder="F-T, AA-AJ" dir="ltr" :error="generator.errors.aisles" />
                </div>
                <TextInput v-model="generator.from" type="number" min="1" :label="t('From Position')" dir="ltr" :error="generator.errors.from" />
                <TextInput v-model="generator.to" type="number" min="1" :label="t('To Position')" dir="ltr" :error="generator.errors.to" />
                <Button type="submit" variant="primary" :loading="generator.processing"><Plus />{{ t('Generate') }}</Button>
            </form>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-4 text-xs text-muted">
                <span>{{ t('Items already have a shelf number from the old system (e.g. E10). Import it to create the locations and place each item in its location.') }}</span>
                <Button size="sm" @click="importShelves"><Boxes />{{ t('Import Shelf Numbers') }}</Button>
            </div>
        </Card>

        <div v-if="warehouseId" class="grid gap-4" :class="selected ? 'xl:grid-cols-3' : ''">
            <div class="grid content-start gap-4" :class="selected ? 'xl:col-span-2' : ''">
                <Card v-for="zone in map.zones" :key="zone.name" :title="zone.name !== '' ? zone.name : t('Warehouse floor')">
                    <div class="-m-2 overflow-x-auto">
                        <table class="border-separate border-spacing-1" dir="ltr">
                            <thead>
                                <tr>
                                    <th class="w-8" />
                                    <th v-for="column in zone.aisles" :key="column.aisle" class="min-w-[3.5rem] rounded-md bg-gray-800 px-2 py-1 text-center font-mono text-sm font-bold text-warning-300 dark:bg-gray-950">
                                        {{ column.aisle }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(position, row) in zone.positions" :key="position">
                                    <td class="text-center font-mono text-sm font-bold text-warning-600">{{ position || '' }}</td>
                                    <td v-for="column in zone.aisles" :key="column.aisle">
                                        <button
                                            v-if="column.cells[row]"
                                            type="button"
                                            :title="column.cells[row]!.code"
                                            class="flex h-12 w-14 flex-col items-center justify-center rounded-md border text-[11px] leading-tight transition"
                                            :class="[cellClass(column.cells[row]!), selected?.id === column.cells[row]!.id ? 'ring-2 ring-brand-500 ring-offset-1 dark:ring-offset-gray-900' : '']"
                                            @click="selectLocation(column.cells[row]!.id)"
                                        >
                                            <span class="font-mono font-semibold">{{ column.cells[row]!.code }}</span>
                                            <span v-if="column.cells[row]!.items" class="inline-flex items-center gap-0.5"><Package class="size-3" />{{ column.cells[row]!.items }}</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>
                <div v-if="!map.zones.length" class="rounded-xl border border-dashed border-line bg-card px-6 py-12 text-center text-sm text-muted">
                    <MapPin class="mx-auto mb-2 size-10 text-gray-300 dark:text-gray-600" />
                    {{ t('No locations yet. Create them from the aisles and positions, or import the shelf numbers from the items.') }}
                </div>
            </div>

            <div v-if="selected" class="h-fit rounded-xl border border-line bg-card shadow-card xl:sticky xl:top-20">
                <div class="flex items-center justify-between border-b border-line px-4 py-3">
                    <div>
                        <div class="font-mono text-2xl font-bold text-brand-600 dark:text-brand-400" dir="ltr">{{ selected.code }}</div>
                        <div class="text-xs text-muted">{{ selected.zone }}</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <Button
                            v-if="can('Shelf Label Print')"
                            size="icon"
                            variant="ghost"
                            external
                            new-tab
                            :aria-label="t('Print Shelf Labels')"
                            :href="route('accounting.inventory.print.shelf-labels', { warehouse: warehouseId, location: selected.id })"
                        >
                            <Printer />
                        </Button>
                        <template v-if="canManage">
                            <Button size="icon" variant="ghost" :aria-label="selected.active ? t('Deactivate') : t('Activate')" @click="toggleLocation"><Eye :class="selected.active ? '' : 'opacity-40'" /></Button>
                            <Button size="icon" variant="ghost" :aria-label="t('Delete')" @click="deleteLocation"><Trash2 class="text-error-500" /></Button>
                        </template>
                        <Button size="icon" variant="ghost" :aria-label="t('Close')" @click="selectLocation(selected.id)"><X /></Button>
                    </div>
                </div>

                <ul class="max-h-[28rem] divide-y divide-line overflow-auto">
                    <li v-for="row in selected.rows" :key="row.product_id" class="flex items-center gap-3 px-4 py-2">
                        <img v-if="row.image" :src="row.image" alt="" class="h-9 w-12 shrink-0 rounded border border-line object-contain" />
                        <div class="min-w-0 flex-1">
                            <a :href="route('accounting.inventory.item-card', { product: row.product_id, warehouse: warehouseId })" class="block truncate text-sm font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ row.name }}</a>
                            <div class="text-xs text-muted">
                                <bdi class="font-mono" dir="ltr">{{ row.barcode }}</bdi>
                                <template v-if="row.placement"> · {{ row.placement }} {{ row.arrow ?? '' }}</template>
                            </div>
                        </div>
                        <span class="font-semibold tabular-nums" dir="ltr">{{ row.quantity }}</span>
                        <button v-if="canManage && row.removable && row.placement_id" type="button" class="text-error-400 hover:text-error-600" :aria-label="t('Remove')" @click="unplace(row.placement_id)"><X class="size-4" /></button>
                    </li>
                    <li v-if="!selected.rows.length" class="px-4 py-8 text-center text-sm text-muted">{{ t('This location is empty.') }}</li>
                </ul>

                <div v-if="canManage" class="grid gap-2 border-t border-line p-4">
                    <div class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ t('Place an item here') }}</div>
                    <SearchPicker :url="route('accounting.inventory.layout.products')" :placeholder="t('Search item by name or scan barcode')" scanner @pick="place" />
                    <div class="flex items-center justify-between gap-2">
                        <div class="w-40"><SelectInput v-model="placing.direction" :options="directionOptions" :placeholder="t('Arrow direction')" /></div>
                        <Toggle v-model="placing.is_primary" :label="t('Primary location')" />
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="rounded-xl border border-dashed border-line bg-card px-6 py-12 text-center text-sm text-muted">
            {{ t('Create a warehouse first, then design its layout here.') }}
            <div v-if="can('Warehouse Create')" class="mt-3"><Button variant="primary" :href="route('accounting.warehouses.add')"><Plus />{{ t('Add Warehouse') }}</Button></div>
        </div>
    </div>
</template>
