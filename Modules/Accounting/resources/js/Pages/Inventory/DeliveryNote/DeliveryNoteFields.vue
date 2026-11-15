<script setup lang="ts">
import { router, type InertiaForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { Check, CircleCheck, ExternalLink, X } from '@/lib/icons';
import { route } from '@/lib/route';

import { cellInput, numberInput } from '../../parts/items-grid/classes';
import { postJson, warehouseContext } from '../parts/api';
import DocumentAlert from '../parts/DocumentAlert.vue';
import LinesTable from '../parts/LinesTable.vue';
import SearchPicker from '../parts/SearchPicker.vue';
import { num, qty, type DocumentRecord, type Option, type OrderOption, type OrderSummary, type WarehouseContext } from '../parts/types';
import type { DeliveryForm, DeliveryLine } from './types';

/**
 * The delivery note form, shared by the add and edit pages: the sales order (its remaining
 * lines), the warehouse, the driver and receiver, then what is delivered from each line with the
 * balance in stock and where the item is kept (the printed pick list). A line above the stock is
 * red; posting refuses it unless the user holds «Stock Allow Negative» (DeliveryNoteService).
 */
const props = defineProps<{
    form: InertiaForm<DeliveryForm>;
    order: OrderSummary | null;
    context: WarehouseContext;
    warehouseOptions: Option[];
    canSeeCost: boolean;
    readonly: boolean;
    record?: DocumentRecord | null;
}>();

const context = ref<WarehouseContext>(props.context);

watch(
    () => props.context,
    (next) => (context.value = next),
);

watch(
    () => props.form.warehouse_id,
    async (warehouseId, previous) => {
        if (props.readonly || String(warehouseId ?? '') === String(previous ?? '')) return;

        try {
            context.value = await warehouseContext('delivery-notes', warehouseId, props.form.items.map((line) => line.product_id));
        } catch {
            // The figures stay as they are; posting checks the stock.
        }
    },
);

function pickOrder(option: Option): void {
    router.get(route('accounting.inventory.delivery-notes.add'), { sales_order: option.value });
}

function scan(option: Option): void {
    props.form.items.forEach((line) => {
        if (Number(line.product_id) === Number(option.value)) line.quantity = Math.min(num(line.quantity) + 1, line.outstanding);
    });
}

function deliverAll(): void {
    props.form.items.forEach((line) => (line.quantity = line.outstanding));
}

function clearQuantities(): void {
    props.form.items.forEach((line) => (line.quantity = null));
}

const filling = ref(false);

/** Each line takes only what the stock covers, after the lines above it (DeliveryNoteService). */
async function deliverAvailable(): Promise<void> {
    if (!props.form.warehouse_id) return;

    filling.value = true;

    try {
        const quantities = await postJson<Record<string, number | null>>(route('accounting.inventory.delivery-notes.available'), {
            warehouse_id: props.form.warehouse_id,
            items: props.form.items.map((line) => ({ key: line.key, product_id: line.product_id, outstanding: line.outstanding })),
        });

        props.form.items.forEach((line) => (line.quantity = quantities[line.key] ?? null));
    } catch {
        // Nothing changes; the user can type the quantities.
    } finally {
        filling.value = false;
    }
}

const onHand = (line: DeliveryLine): number => num(context.value.levels[line.product_id]?.on_hand);
const short = (line: DeliveryLine): boolean => !props.readonly && num(line.quantity) > onHand(line) + 0.00005;
const remaining = (line: DeliveryLine): number => (props.readonly ? Math.max(line.outstanding - num(line.quantity), 0) : line.outstanding);
const sum = (lines: DeliveryLine[], pick: (line: DeliveryLine) => number): number => lines.reduce((total, line) => total + pick(line), 0);
const rowClass = (line: DeliveryLine): string => (short(line) ? 'bg-error-50 dark:bg-error-500/10' : num(line.quantity) > 0 ? '' : 'opacity-60');

const totalQuantity = computed(() => sum(props.form.items, (line) => num(line.quantity)));
const totalWeight = computed(() => sum(props.form.items, (line) => num(line.quantity) * line.weight_per_unit));
const columns = computed(() => (props.canSeeCost ? 7 : 6));
</script>

<template>
    <div class="grid gap-4">
        <DocumentAlert />

        <Card :title="t('Delivery Info')">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="sm:col-span-2">
                    <div v-if="order" class="grid gap-1.5">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('Sales Order') }}</span>
                        <div class="flex h-10 items-center justify-between gap-2 rounded-lg border border-line bg-gray-50 px-3.5 text-sm dark:bg-white/[0.03]">
                            <span class="truncate">
                                <bdi class="font-semibold text-brand-600 dark:text-brand-400" dir="ltr">{{ order.reference_no }}</bdi>
                                <span class="text-muted"> — {{ order.party }} · {{ order.date }}</span>
                            </span>
                            <a :href="route('accounting.sales-orders.edit', order.id)" target="_blank" class="inline-flex shrink-0 items-center gap-1 text-xs text-brand-600 hover:underline dark:text-brand-400">
                                <ExternalLink class="size-3.5" />{{ t('Open Order') }}
                            </a>
                        </div>
                    </div>
                    <SearchPicker
                        v-else
                        :url="route('accounting.inventory.delivery-notes.orders')"
                        :label="t('Sales Order')"
                        :placeholder="t('Search by order number or customer')"
                        search-empty
                        @pick="pickOrder"
                    >
                        <template #option="{ option }">
                            <span>
                                <bdi class="font-semibold" dir="ltr">{{ option.label }}</bdi>
                                <span class="text-muted"> — {{ (option as OrderOption).party }}</span>
                            </span>
                            <span class="text-xs text-muted">{{ (option as OrderOption).date }} · {{ (option as OrderOption).status }}</span>
                        </template>
                    </SearchPicker>
                    <p v-if="form.errors.sales_order_id" class="mt-1 text-xs text-error-600">{{ form.errors.sales_order_id }}</p>
                </div>
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" required :options="warehouseOptions" :placeholder="t('Select a warehouse')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <TextInput v-model="form.delivery_date" type="date" :label="t('Delivery Date')" required :disabled="readonly" :error="form.errors.delivery_date" />
                <TextInput v-model="form.driver_name" :label="t('Driver Name')" :disabled="readonly" :error="form.errors.driver_name" />
                <TextInput v-model="form.vehicle_no" :label="t('Vehicle No')" :disabled="readonly" :error="form.errors.vehicle_no" />
                <TextInput v-model="form.receiver_name" :label="t('Receiver Name')" :disabled="readonly" :error="form.errors.receiver_name" />
                <TextInput v-model="form.delivery_address" :label="t('Delivery Address')" :disabled="readonly" :error="form.errors.delivery_address" />
                <div class="sm:col-span-2 xl:col-span-4">
                    <TextInput v-model="form.notes" :label="t('Notes')" :disabled="readonly" :error="form.errors.notes" />
                </div>
            </div>
        </Card>

        <Card v-if="order">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <h2 class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-white">
                    {{ t('Items') }}
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 tabular-nums dark:bg-white/10 dark:text-gray-300">{{ form.items.length }}</span>
                </h2>
                <div v-if="!readonly" class="flex flex-wrap items-end gap-2">
                    <div class="w-72">
                        <SearchPicker :url="route('accounting.inventory.delivery-notes.products')" :placeholder="t('Scan barcode to count +1')" scanner @pick="scan" />
                    </div>
                    <Button :loading="filling" :disabled="!form.warehouse_id" @click="deliverAvailable"><CircleCheck />{{ t('Fill From Available Stock') }}</Button>
                    <Button @click="deliverAll"><Check />{{ t('Deliver All Remaining') }}</Button>
                    <Button @click="clearQuantities"><X />{{ t('Clear Quantities') }}</Button>
                </div>
            </div>

            <LinesTable :lines="form.items" :columns="columns" :empty="t('Everything on this sales order has been delivered.')" :row-class="rowClass">
                <template #head>
                    <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('Ordered') }}</th>
                    <th class="w-28 px-2 py-2.5 text-end font-medium">{{ t('Previously Delivered') }}</th>
                    <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('Remaining') }}</th>
                    <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('In Stock') }}</th>
                    <th class="w-32 px-2 py-2.5 text-center font-medium">{{ t('Delivered Qty') }}</th>
                    <th class="w-48 px-2 py-2.5 text-start font-medium">{{ t('Pick From') }}</th>
                    <th v-if="canSeeCost" class="w-28 px-2 py-2.5 text-end font-medium">{{ t('Cost') }}</th>
                </template>
                <template #subhead>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('Piece Length') }}</th>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('Ordered') }}</th>
                    <th class="w-24 px-2 py-1 text-end font-medium">{{ t('Previously Delivered') }}</th>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('Remaining') }}</th>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('In Stock') }}</th>
                    <th class="w-28 px-2 py-1 text-center font-medium">{{ t('Delivered Qty') }}</th>
                    <th class="w-48 px-2 py-1 text-start font-medium">{{ t('Pick From') }}</th>
                    <th v-if="canSeeCost" class="w-24 px-2 py-1 text-end font-medium">{{ t('Cost') }}</th>
                </template>

                <template #main="{ lines }">
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => line.ordered)) }}</td>
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => line.delivered)) }}</td>
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, remaining)) }}</td>
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, onHand)) }}</td>
                    <td class="px-2 py-2 text-center font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => num(line.quantity))) }}</td>
                    <td />
                    <td v-if="canSeeCost" />
                </template>

                <template #details="{ line }">
                    <div v-if="!line.group_key && line.piece_length > 0" class="text-xs text-muted">{{ t('Length') }}: <span dir="ltr">{{ qty(line.piece_length) }}</span></div>
                </template>

                <template #cells="{ line, nested }">
                    <td v-if="nested" class="px-2 py-1.5 text-end tabular-nums" dir="ltr">{{ line.piece_length > 0 ? qty(line.piece_length) : '—' }}</td>
                    <td class="px-2 text-end tabular-nums" dir="ltr">{{ qty(line.ordered) }}</td>
                    <td class="px-2 text-end text-muted tabular-nums" dir="ltr">{{ qty(line.delivered) }}</td>
                    <td class="px-2 text-end font-semibold tabular-nums" :class="remaining(line) > 0 ? 'text-warning-600' : 'text-success-600'" dir="ltr">{{ qty(remaining(line)) }}</td>
                    <td class="px-2 text-end tabular-nums" :class="onHand(line) <= 0 ? 'text-error-600' : ''" dir="ltr">{{ qty(onHand(line)) }}</td>
                    <td class="px-2 py-1">
                        <input
                            v-if="!readonly"
                            v-model="line.quantity"
                            type="number"
                            step="any"
                            min="0"
                            :max="line.outstanding"
                            :class="[numberInput, 'w-24 text-center', short(line) ? '!border-error-400' : '']"
                            dir="ltr"
                        />
                        <div v-else class="text-center font-semibold tabular-nums" dir="ltr">{{ qty(line.quantity) }}</div>
                    </td>
                    <td class="px-2 py-1">
                        <template v-if="!readonly">
                            <select v-model="line.location_id" :class="[cellInput, 'w-44']">
                                <option :value="null">{{ t('Auto (primary first)') }}</option>
                                <option v-for="location in context.locations" :key="location.value" :value="location.value">{{ location.label }}</option>
                            </select>
                            <div v-if="context.placements[line.product_id]?.length" class="mt-1 flex flex-wrap gap-1">
                                <span
                                    v-for="place in context.placements[line.product_id]"
                                    :key="place.location_id"
                                    class="rounded px-1.5 py-0.5 font-mono text-[11px]"
                                    :class="place.is_primary ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'"
                                    dir="ltr"
                                >{{ place.code }}: {{ qty(place.quantity) }}</span>
                            </div>
                        </template>
                        <div v-else class="flex flex-wrap gap-1">
                            <span v-for="(pick, position) in line.picked" :key="position" class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-700 dark:bg-white/10 dark:text-gray-200" dir="ltr">
                                {{ pick.code ?? t('Unassigned') }}: {{ qty(pick.quantity) }}
                            </span>
                        </div>
                    </td>
                    <td v-if="canSeeCost" class="px-2 text-end text-gray-600 tabular-nums dark:text-gray-300" dir="ltr">
                        {{ record?.is_posted ? qty(line.unit_cost * num(line.quantity), 2) : '—' }}
                    </td>
                </template>
            </LinesTable>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-6 rounded-lg border border-line bg-gray-50/80 px-4 py-3 text-sm dark:bg-white/[0.02]">
                <span><span class="text-muted">{{ t('Total Quantity') }}:</span> <b class="tabular-nums" dir="ltr">{{ qty(totalQuantity, 2) }}</b></span>
                <span><span class="text-muted">{{ t('Total Weight (kg)') }}:</span> <b class="tabular-nums" dir="ltr">{{ qty(totalWeight, 2) }}</b></span>
                <span v-if="canSeeCost && record?.is_posted && record.total_cost !== null">
                    <span class="text-muted">{{ t('Cost of Goods Sold') }}:</span>
                    <b class="text-lg text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(record.total_cost, 2) }}</b>
                </span>
            </div>
        </Card>
    </div>
</template>
