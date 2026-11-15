<script setup lang="ts">
import { router, type InertiaForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { CircleCheck, ExternalLink, X } from '@/lib/icons';
import { route } from '@/lib/route';

import { cellInput, numberInput } from '../../parts/items-grid/classes';
import { postJson, warehouseContext } from '../parts/api';
import DocumentAlert from '../parts/DocumentAlert.vue';
import LinesTable from '../parts/LinesTable.vue';
import SearchPicker from '../parts/SearchPicker.vue';
import { num, qty, type Option, type OrderOption, type OrderSummary, type WarehouseContext } from '../parts/types';
import type { ReceiptForm, ReceiptLine } from './types';

/**
 * The goods receipt form, shared by the add and edit pages: the purchase order (its remaining
 * lines, a weight item with its pieces), the warehouse and the receipt details, then the received
 * quantity and location of every line. Read only once posted or cancelled. Every rule (remaining
 * quantity, value of the weight pieces, posting) is GoodsReceiptService's.
 */
const props = defineProps<{
    form: InertiaForm<ReceiptForm>;
    order: OrderSummary | null;
    context: WarehouseContext;
    warehouseOptions: Option[];
    canSeeCost: boolean;
    readonly: boolean;
    recordId?: number | null;
    /** The value the server computed with the page; the stock value of a posted receipt. */
    value: string | null;
}>();

const context = ref<WarehouseContext>(props.context);
const value = ref<string | null>(props.value);

watch(
    () => props.context,
    (next) => (context.value = next),
);
watch(
    () => props.value,
    (next) => (value.value = next),
);

/** A new warehouse: its locations, and every line takes the item's primary location there. */
watch(
    () => props.form.warehouse_id,
    async (warehouseId, previous) => {
        if (props.readonly || String(warehouseId ?? '') === String(previous ?? '')) return;

        try {
            context.value = await warehouseContext('goods-receipts', warehouseId, props.form.items.map((line) => line.product_id));
            props.form.items.forEach((line) => (line.location_id = context.value.primary[line.product_id] ?? null));
        } catch {
            // The lines keep their locations; saving checks them.
        }
    },
);

function pickOrder(option: Option): void {
    router.get(route('accounting.inventory.goods-receipts.add'), { purchase_order: option.value });
}

/** The scanner on a receipt counts: +1 on the item's line, never above what remains. */
function scan(option: Option): void {
    props.form.items.forEach((line) => {
        if (Number(line.product_id) === Number(option.value)) line.quantity = Math.min(num(line.quantity) + 1, line.outstanding);
    });
}

function receiveAll(): void {
    props.form.items.forEach((line) => (line.quantity = line.outstanding));
}

function clearQuantities(): void {
    props.form.items.forEach((line) => (line.quantity = null));
}

const remaining = (line: ReceiptLine): number => (props.readonly ? Math.max(line.outstanding - num(line.quantity), 0) : line.outstanding);
const sum = (lines: ReceiptLine[], pick: (line: ReceiptLine) => number): number => lines.reduce((total, line) => total + pick(line), 0);
const totalQuantity = computed(() => sum(props.form.items, (line) => num(line.quantity)));
const totalWeight = computed(() => sum(props.form.items, (line) => num(line.quantity) * line.weight_per_unit));

/** The value of the typed quantities, from the server (a weight piece's share of its line). */
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => props.form.items.map((line) => line.quantity).join('|'),
    () => {
        if (props.readonly || !props.canSeeCost || !props.form.purchase_order_id) return;

        clearTimeout(timer);
        timer = setTimeout(async () => {
            try {
                value.value = (
                    await postJson<{ value: string | null }>(route('accounting.inventory.goods-receipts.value'), {
                        purchase_order_id: props.form.purchase_order_id,
                        receipt_id: props.recordId ?? null,
                        items: props.form.items.map((line) => ({
                            purchase_order_item_id: line.purchase_order_item_id,
                            purchase_order_item_piece_id: line.purchase_order_item_piece_id,
                            quantity: line.quantity,
                        })),
                    })
                ).value;
            } catch {
                // The figure updates on the next change or after saving.
            }
        }, 400);
    },
);

const columns = computed(() => (props.canSeeCost ? 7 : 6));
</script>

<template>
    <div class="grid gap-4">
        <DocumentAlert />

        <Card :title="t('Receipt Info')">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="sm:col-span-2">
                    <div v-if="order" class="grid gap-1.5">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('Purchase Order') }}</span>
                        <div class="flex h-10 items-center justify-between gap-2 rounded-lg border border-line bg-gray-50 px-3.5 text-sm dark:bg-white/[0.03]">
                            <span class="truncate">
                                <bdi class="font-semibold text-brand-600 dark:text-brand-400" dir="ltr">{{ order.reference_no }}</bdi>
                                <span class="text-muted"> — {{ order.party }} · {{ order.date }}</span>
                            </span>
                            <a :href="route('accounting.purchase-orders.edit', order.id)" target="_blank" class="inline-flex shrink-0 items-center gap-1 text-xs text-brand-600 hover:underline dark:text-brand-400">
                                <ExternalLink class="size-3.5" />{{ t('Open Order') }}
                            </a>
                        </div>
                    </div>
                    <SearchPicker
                        v-else
                        :url="route('accounting.inventory.goods-receipts.orders')"
                        :label="t('Purchase Order')"
                        :placeholder="t('Search by order number or supplier')"
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
                    <p v-if="form.errors.purchase_order_id" class="mt-1 text-xs text-error-600">{{ form.errors.purchase_order_id }}</p>
                </div>
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" required :options="warehouseOptions" :placeholder="t('Select a warehouse')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <TextInput v-model="form.receipt_date" type="date" :label="t('Receipt Date')" required :disabled="readonly" :error="form.errors.receipt_date" />
                <TextInput v-model="form.supplier_document_no" :label="t('Supplier Document No')" :disabled="readonly" :error="form.errors.supplier_document_no" />
                <TextInput v-model="form.container_no" :label="t('Container No')" :disabled="readonly" :error="form.errors.container_no" />
                <TextInput v-model="form.received_by_name" :label="t('Received By')" :disabled="readonly" :error="form.errors.received_by_name" />
                <TextInput v-model="form.notes" :label="t('Notes')" :disabled="readonly" :error="form.errors.notes" />
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
                        <SearchPicker :url="route('accounting.inventory.goods-receipts.products')" :placeholder="t('Scan barcode to count +1')" scanner @pick="scan" />
                    </div>
                    <Button @click="receiveAll"><CircleCheck />{{ t('Receive All Remaining') }}</Button>
                    <Button @click="clearQuantities"><X />{{ t('Clear Quantities') }}</Button>
                </div>
            </div>

            <LinesTable
                :lines="form.items"
                :columns="columns"
                :empty="t('Everything on this purchase order has been received.')"
                :row-class="(line) => (num(line.quantity) > 0 ? '' : 'opacity-60')"
            >
                <template #head>
                    <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('Ordered') }}</th>
                    <th class="w-28 px-2 py-2.5 text-end font-medium">{{ t('Previously Received') }}</th>
                    <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('Remaining') }}</th>
                    <th class="w-32 px-2 py-2.5 text-center font-medium">{{ t('Received Qty') }}</th>
                    <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('Weight (kg)') }}</th>
                    <th class="w-40 px-2 py-2.5 text-start font-medium">{{ t('Location') }}</th>
                    <th v-if="canSeeCost" class="w-28 px-2 py-2.5 text-end font-medium">{{ t('Unit Cost') }}</th>
                </template>
                <template #subhead>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('Piece Length') }}</th>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('Ordered') }}</th>
                    <th class="w-24 px-2 py-1 text-end font-medium">{{ t('Previously Received') }}</th>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('Remaining') }}</th>
                    <th class="w-28 px-2 py-1 text-center font-medium">{{ t('Received Qty') }}</th>
                    <th class="w-20 px-2 py-1 text-end font-medium">{{ t('Weight (kg)') }}</th>
                    <th class="w-40 px-2 py-1 text-start font-medium">{{ t('Location') }}</th>
                    <th v-if="canSeeCost" class="w-24 px-2 py-1 text-end font-medium">{{ t('Unit Cost') }}</th>
                </template>

                <template #main="{ lines }">
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => line.ordered)) }}</td>
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => line.received)) }}</td>
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, remaining)) }}</td>
                    <td class="px-2 py-2 text-center font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => num(line.quantity))) }}</td>
                    <td class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(sum(lines, (line) => num(line.quantity) * line.weight_per_unit), 2) }}</td>
                    <td />
                    <td v-if="canSeeCost" />
                </template>

                <template #details="{ line }">
                    <div v-if="!line.group_key && line.piece_length > 0" class="text-xs text-muted">{{ t('Length') }}: <span dir="ltr">{{ qty(line.piece_length) }}</span></div>
                </template>

                <template #cells="{ line, nested }">
                    <td v-if="nested" class="px-2 py-1.5 text-end tabular-nums" dir="ltr">{{ line.piece_length > 0 ? qty(line.piece_length) : '—' }}</td>
                    <td class="px-2 text-end tabular-nums" dir="ltr">{{ qty(line.ordered) }}</td>
                    <td class="px-2 text-end text-muted tabular-nums" dir="ltr">{{ qty(line.received) }}</td>
                    <td class="px-2 text-end font-semibold tabular-nums" :class="remaining(line) > 0 ? 'text-warning-600' : 'text-success-600'" dir="ltr">{{ qty(remaining(line)) }}</td>
                    <td class="px-2 py-1">
                        <input v-if="!readonly" v-model="line.quantity" type="number" step="any" min="0" :max="line.outstanding" :class="[numberInput, 'w-24 text-center']" dir="ltr" />
                        <div v-else class="text-center font-semibold tabular-nums" dir="ltr">{{ qty(line.quantity) }}</div>
                    </td>
                    <td class="px-2 text-end text-gray-600 tabular-nums dark:text-gray-300" dir="ltr">{{ qty(num(line.quantity) * line.weight_per_unit, 2) }}</td>
                    <td class="px-2 py-1">
                        <select v-if="!readonly" v-model="line.location_id" :class="[cellInput, 'w-36']">
                            <option :value="null">{{ t('No location') }}</option>
                            <option v-for="location in context.locations" :key="location.value" :value="location.value">{{ location.label }}</option>
                        </select>
                        <bdi v-else class="font-mono text-xs" dir="ltr">{{ line.location_code ?? '—' }}</bdi>
                    </td>
                    <td v-if="canSeeCost" class="px-2 text-end text-gray-600 tabular-nums dark:text-gray-300" dir="ltr">{{ qty(line.unit_cost) }}</td>
                </template>
            </LinesTable>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-6 rounded-lg border border-line bg-gray-50/80 px-4 py-3 text-sm dark:bg-white/[0.02]">
                <span><span class="text-muted">{{ t('Total Quantity') }}:</span> <b class="tabular-nums" dir="ltr">{{ qty(totalQuantity, 2) }}</b></span>
                <span><span class="text-muted">{{ t('Total Weight (kg)') }}:</span> <b class="tabular-nums" dir="ltr">{{ qty(totalWeight, 2) }}</b></span>
                <span v-if="canSeeCost && value !== null">
                    <span class="text-muted">{{ t('Stock Value') }}:</span>
                    <b class="text-lg text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(value, 2) }}</b>
                </span>
            </div>
        </Card>
    </div>
</template>
