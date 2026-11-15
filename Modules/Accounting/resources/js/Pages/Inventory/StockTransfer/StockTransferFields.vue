<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import { cellInput, iconButton, numberInput } from '../../parts/items-grid/classes';
import { getJson, warehouseContext } from '../parts/api';
import DocumentAlert from '../parts/DocumentAlert.vue';
import LinesTable from '../parts/LinesTable.vue';
import SearchPicker from '../parts/SearchPicker.vue';
import { num, qty, type Option, type WarehouseContext } from '../parts/types';
import type { TransferForm, TransferLine } from './types';

/**
 * The stock transfer form, shared by the add and edit pages: from one warehouse to another, or
 * within one warehouse to another location when the item's place is full. Items are added by
 * name or barcode; each line shows the stock in the source warehouse and where it is kept.
 */
const props = defineProps<{
    form: InertiaForm<TransferForm>;
    fromContext: WarehouseContext;
    toContext: WarehouseContext;
    warehouseOptions: Option[];
    canSeeCost: boolean;
    readonly: boolean;
    posted?: boolean;
}>();

const fromContext = ref<WarehouseContext>(props.fromContext);
const toContext = ref<WarehouseContext>(props.toContext);

watch(
    () => [props.fromContext, props.toContext] as const,
    ([from, to]) => {
        fromContext.value = from;
        toContext.value = to;
    },
);

const productIds = (): number[] => props.form.items.map((line) => line.product_id);

async function refreshFrom(): Promise<void> {
    try {
        fromContext.value = await warehouseContext('transfers', props.form.from_warehouse_id, productIds());
    } catch {
        // The figures stay as they are; posting checks the stock.
    }
}

watch(
    () => props.form.from_warehouse_id,
    (value, previous) => {
        if (!props.readonly && String(value ?? '') !== String(previous ?? '')) refreshFrom();
    },
);

watch(
    () => props.form.to_warehouse_id,
    async (value, previous) => {
        if (props.readonly || String(value ?? '') === String(previous ?? '')) return;

        try {
            toContext.value = await warehouseContext('transfers', value, []);
        } catch {
            // The list stays as it is.
        }
    },
);

const sameWarehouse = computed(() => !!props.form.from_warehouse_id && String(props.form.from_warehouse_id) === String(props.form.to_warehouse_id));

async function addProduct(option: Option): Promise<void> {
    try {
        const line = await getJson<TransferLine | null>(route('accounting.inventory.transfers.line', { product_id: option.value }));

        if (!line) return;

        props.form.items.push(line);
        await refreshFrom();
    } catch {
        // Nothing added; the user can try again.
    }
}

function removeLine(index: number): void {
    props.form.items.splice(index, 1);
}

const onHand = (line: TransferLine): number => num(fromContext.value.levels[line.product_id]?.on_hand);
const showLineCost = computed(() => props.canSeeCost && !!props.posted);
const columns = computed(() => 5 + (showLineCost.value ? 1 : 0));
const sum = (lines: TransferLine[], pick: (line: TransferLine) => number): number => lines.reduce((total, line) => total + pick(line), 0);
</script>

<template>
    <div class="grid gap-4">
        <DocumentAlert />

        <Card>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <SelectInput v-model="form.from_warehouse_id" :label="t('From Warehouse')" required :options="warehouseOptions" :placeholder="t('Select a warehouse')" :disabled="readonly" :error="form.errors.from_warehouse_id" />
                <SelectInput
                    v-model="form.to_warehouse_id"
                    :label="t('To Warehouse')"
                    required
                    :options="warehouseOptions"
                    :placeholder="t('Select a warehouse')"
                    :disabled="readonly"
                    :hint="sameWarehouse ? t('Same warehouse: moving items between locations.') : undefined"
                    :error="form.errors.to_warehouse_id"
                />
                <TextInput v-model="form.transfer_date" type="date" :label="t('Transfer Date')" required :disabled="readonly" :error="form.errors.transfer_date" />
                <TextInput v-model="form.notes" :label="t('Notes')" :disabled="readonly" :error="form.errors.notes" />
            </div>
        </Card>

        <Card>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ t('Items') }}</h2>
                <div v-if="!readonly" class="w-full max-w-md">
                    <SearchPicker :url="route('accounting.inventory.transfers.products')" :placeholder="t('Search item by name or scan barcode')" scanner @pick="addProduct" />
                </div>
            </div>

            <LinesTable :lines="form.items" :columns="columns" :empty="t('Add items using the search box or a barcode scanner.')">
                <template #head>
                    <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('In Stock') }}</th>
                    <th class="w-32 px-2 py-2.5 text-center font-medium">{{ t('Quantity') }}</th>
                    <th class="w-40 px-2 py-2.5 text-start font-medium">{{ t('From Location') }}</th>
                    <th class="w-40 px-2 py-2.5 text-start font-medium">{{ t('To Location') }}</th>
                    <th v-if="showLineCost" class="w-28 px-2 py-2.5 text-end font-medium">{{ t('Cost') }}</th>
                    <th class="w-12 px-2 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                </template>
                <template #subhead>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('In Stock') }}</th>
                    <th class="w-28 px-2 py-1 text-center font-medium">{{ t('Quantity') }}</th>
                    <th class="w-40 px-2 py-1 text-start font-medium">{{ t('From Location') }}</th>
                    <th class="w-40 px-2 py-1 text-start font-medium">{{ t('To Location') }}</th>
                    <th v-if="showLineCost" class="w-24 px-2 py-1 text-end font-medium">{{ t('Cost') }}</th>
                    <th class="w-10 px-2 py-1"><span class="sr-only">{{ t('Actions') }}</span></th>
                </template>

                <template #main="{ lines }">
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ readonly ? '—' : qty(sum(lines, onHand)) }}</td>
                    <td class="px-2 py-2 text-center font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => num(line.quantity))) }}</td>
                    <td />
                    <td />
                    <td v-if="showLineCost" class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => num(line.total_cost)), 2) }}</td>
                    <td />
                </template>

                <template #details="{ line }">
                    <div v-if="!readonly && fromContext.placements[line.product_id]?.length" class="mt-1 flex flex-wrap gap-1">
                        <span
                            v-for="place in fromContext.placements[line.product_id]"
                            :key="place.location_id"
                            class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300"
                            dir="ltr"
                        >{{ place.code }}: {{ qty(place.quantity) }}</span>
                    </div>
                </template>

                <template #cells="{ line, index }">
                    <td class="px-2 text-end tabular-nums" dir="ltr">{{ readonly ? '—' : qty(onHand(line)) }}</td>
                    <td class="px-2 py-1">
                        <input v-if="!readonly" v-model="line.quantity" type="number" step="any" min="0" :class="[numberInput, 'w-24 text-center']" dir="ltr" />
                        <div v-else class="text-center font-semibold tabular-nums" dir="ltr">{{ qty(line.quantity) }}</div>
                    </td>
                    <td class="px-2 py-1">
                        <select v-if="!readonly" v-model="line.from_location_id" :class="[cellInput, 'w-36']">
                            <option :value="null">{{ t('Auto (primary first)') }}</option>
                            <option v-for="location in fromContext.locations" :key="location.value" :value="location.value">{{ location.label }}</option>
                        </select>
                        <bdi v-else class="font-mono text-xs" dir="ltr">{{ line.from_code ?? '—' }}</bdi>
                    </td>
                    <td class="px-2 py-1">
                        <select v-if="!readonly" v-model="line.to_location_id" :class="[cellInput, 'w-36']">
                            <option :value="null">{{ t('Primary location') }}</option>
                            <option v-for="location in toContext.locations" :key="location.value" :value="location.value">{{ location.label }}</option>
                        </select>
                        <bdi v-else class="font-mono text-xs" dir="ltr">{{ line.to_code ?? '—' }}</bdi>
                    </td>
                    <td v-if="showLineCost" class="px-2 text-end tabular-nums" dir="ltr">{{ qty(line.total_cost, 2) }}</td>
                    <td class="px-2 text-end">
                        <button v-if="!readonly" type="button" :class="[iconButton, 'hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10']" :aria-label="t('Remove')" :title="t('Remove')" @click="removeLine(index)">
                            <Trash2 />
                        </button>
                    </td>
                </template>
            </LinesTable>
            <p v-if="form.errors.items" class="mt-2 text-xs text-error-600">{{ form.errors.items }}</p>
        </Card>
    </div>
</template>
