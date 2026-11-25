<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { Info, List, Trash2 } from '@/lib/icons';
import { route } from '@/lib/route';

import { cellInput, iconButton, numberInput } from '../../parts/items-grid/classes';
import { getJson, warehouseContext } from '../parts/api';
import DocumentAlert from '../parts/DocumentAlert.vue';
import LinesTable from '../parts/LinesTable.vue';
import SearchPicker from '../parts/SearchPicker.vue';
import { num, qty, type Option, type WarehouseContext } from '../parts/types';
import type { AdjustmentForm, AdjustmentLine } from './types';

/**
 * Opening balance, stock count or adjustment, shared by the add and edit pages. A count loads the
 * warehouse's items (or one aluminium type) with their system balance; the counted quantity is
 * typed and the difference shows before posting. The difference at posting time, the cost and the
 * journal entry are StockAdjustmentService's.
 */
const props = defineProps<{
    form: InertiaForm<AdjustmentForm>;
    context: WarehouseContext;
    warehouseOptions: Option[];
    typeOptions: Option[];
    canSeeCost: boolean;
    readonly: boolean;
    /** The type cannot change once saved. */
    saved?: boolean;
}>();

const context = ref<WarehouseContext>(props.context);

watch(
    () => props.context,
    (next) => (context.value = next),
);

const isCount = computed(() => props.form.type === 'count');
const isOpening = computed(() => props.form.type === 'opening');

async function refreshLevels(): Promise<void> {
    if (props.readonly) return;

    try {
        context.value = await warehouseContext('adjustments', props.form.warehouse_id, props.form.items.map((line) => line.product_id));
    } catch {
        // The figures stay as they are; posting takes the balance at that moment.
    }
}

watch(
    () => props.form.warehouse_id,
    (value, previous) => {
        if (String(value ?? '') !== String(previous ?? '')) refreshLevels();
    },
);

/** Scanning an item already on the sheet (without a location) counts it once more. */
async function addProduct(option: Option): Promise<void> {
    const field = isCount.value ? 'counted_quantity' : 'quantity';
    const existing = props.form.items.find((line) => Number(line.product_id) === Number(option.value) && !line.location_id);

    if (existing) {
        existing[field] = num(existing[field]) + 1;

        return;
    }

    try {
        const line = await getJson<AdjustmentLine | null>(
            route('accounting.inventory.adjustments.line', { product_id: option.value, type: props.form.type, warehouse_id: props.form.warehouse_id || undefined }),
        );

        if (!line) return;

        props.form.items.push(line);
        await refreshLevels();
    } catch {
        // Nothing added; the user can try again.
    }
}

const loading = ref(false);

/** The count sheet: the warehouse's items, or the colours of one price group, without those already listed. */
async function loadCountSheet(priceGroupId: number | string | null): Promise<void> {
    if (!props.form.warehouse_id) return;

    if (priceGroupId === null && !(await confirm({ title: t('Load all items stored in this warehouse?'), confirmLabel: t('Load Warehouse Items'), tone: 'primary' }))) return;

    loading.value = true;

    try {
        const rows = await getJson<AdjustmentLine[]>(
            route('accounting.inventory.adjustments.count-sheet', {
                warehouse_id: props.form.warehouse_id,
                price_group_id: priceGroupId ?? undefined,
                except: props.form.items.map((line) => line.product_id),
            }),
        );

        props.form.items.push(...rows);
        await refreshLevels();
    } catch {
        // Nothing loaded; the user can try again.
    } finally {
        loading.value = false;
    }
}

function removeLine(index: number): void {
    props.form.items.splice(index, 1);
}

/** The system balance (live while editing) and the difference: counted − system, or the quantity. */
function system(line: AdjustmentLine): number {
    return props.readonly ? num(line.system_quantity) : num(context.value.levels[line.product_id]?.on_hand ?? line.system_quantity);
}

function difference(line: AdjustmentLine): number | null {
    if (props.readonly) return num(line.quantity);

    if (!isCount.value) return num(line.quantity);

    return line.counted_quantity === null || line.counted_quantity === '' ? null : num(line.counted_quantity) - system(line);
}

const signed = (value: number | null): string => (value === null ? '—' : `${value > 0 ? '+' : ''}${qty(value)}`);
const tone = (value: number | null): string => (value === null || value === 0 ? 'text-gray-400' : value < 0 ? 'text-error-600' : 'text-success-600');

function groupDifference(lines: AdjustmentLine[]): number | null {
    const values = lines.map(difference).filter((value): value is number => value !== null);

    return values.length ? values.reduce((total, value) => total + value, 0) : null;
}

const columns = computed(() => 1 + (isOpening.value ? 0 : 1) + (isCount.value ? 2 : 1) + (props.canSeeCost ? 1 : 0) + 1);
const hint = computed(() =>
    isCount.value
        ? t('Enter the counted quantity. The difference from the system balance at posting time is recorded as surplus or shortage.')
        : isOpening.value
          ? t('Enter the quantities on hand when you start using the system, with their unit cost.')
          : t('Positive quantity adds to stock, negative quantity removes from stock (damage, breakage, samples...).'),
);
</script>

<template>
    <div class="grid gap-4">
        <DocumentAlert />

        <Card>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <SelectInput v-model="form.type" :label="t('Type')" :options="typeOptions" :disabled="readonly || saved" :error="form.errors.type" />
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" required :options="warehouseOptions" :placeholder="t('Select a warehouse')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <TextInput v-model="form.adjustment_date" type="date" :label="t('Date')" required :disabled="readonly" :error="form.errors.adjustment_date" />
                <TextInput
                    v-model="form.reason"
                    :label="t('Reason')"
                    :disabled="readonly"
                    :placeholder="isOpening ? t('Starting balances') : t('Damage, breakage, annual count...')"
                    :error="form.errors.reason"
                />
                <TextInput v-model="form.notes" :label="t('Notes')" :disabled="readonly" :error="form.errors.notes" />
            </div>
            <p class="mt-4 flex items-start gap-2 rounded-lg bg-blue-light-50 px-3 py-2 text-xs text-blue-light-700 dark:bg-blue-light-500/10 dark:text-blue-light-300">
                <Info class="mt-0.5 size-4 shrink-0" />{{ hint }}
            </p>
        </Card>

        <Card>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <h2 class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-white">
                    {{ t('Items') }}
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 tabular-nums dark:bg-white/10 dark:text-gray-300">{{ form.items.length }}</span>
                </h2>
                <div v-if="!readonly" class="flex flex-1 flex-wrap items-end justify-end gap-2">
                    <div class="w-full max-w-sm">
                        <SearchPicker :url="route('accounting.inventory.adjustments.products')" :placeholder="t('Search item by name or scan barcode')" scanner @pick="addProduct" />
                    </div>
                    <template v-if="isCount">
                        <div class="w-64">
                            <SearchPicker :url="route('accounting.inventory.adjustments.price-groups')" :placeholder="t('Load a price group...')" @pick="(option) => loadCountSheet(option.value)" />
                        </div>
                        <Button :loading="loading" :disabled="!form.warehouse_id" @click="loadCountSheet(null)"><List />{{ t('Load Warehouse Items') }}</Button>
                    </template>
                </div>
            </div>

            <LinesTable :lines="form.items" :columns="columns" :empty="t('Add items using the search box or a barcode scanner.')">
                <template #head>
                    <th class="w-40 px-2 py-2.5 text-start font-medium">{{ t('Location') }}</th>
                    <th v-if="!isOpening" class="w-24 px-2 py-2.5 text-end font-medium">{{ t('System Qty') }}</th>
                    <template v-if="isCount">
                        <th class="w-32 px-2 py-2.5 text-center font-medium">{{ t('Counted Qty') }}</th>
                        <th class="w-24 px-2 py-2.5 text-end font-medium">{{ t('Difference') }}</th>
                    </template>
                    <th v-else class="w-32 px-2 py-2.5 text-center font-medium">{{ t('Quantity') }}</th>
                    <th v-if="canSeeCost" class="w-32 px-2 py-2.5 text-center font-medium">{{ t('Unit Cost') }}</th>
                    <th class="w-12 px-2 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                </template>
                <template #subhead>
                    <th class="w-40 px-2 py-1 text-start font-medium">{{ t('Location') }}</th>
                    <th v-if="!isOpening" class="w-20 px-2 py-1 text-end font-medium">{{ t('System Qty') }}</th>
                    <template v-if="isCount">
                        <th class="w-28 px-2 py-1 text-center font-medium">{{ t('Counted Qty') }}</th>
                        <th class="w-20 px-2 py-1 text-end font-medium">{{ t('Difference') }}</th>
                    </template>
                    <th v-else class="w-28 px-2 py-1 text-center font-medium">{{ t('Quantity') }}</th>
                    <th v-if="canSeeCost" class="w-32 px-2 py-1 text-center font-medium">{{ t('Unit Cost') }}</th>
                    <th class="w-10 px-2 py-1"><span class="sr-only">{{ t('Actions') }}</span></th>
                </template>

                <template #main="{ lines }">
                    <td />
                    <td v-if="!isOpening" class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ qty(lines.reduce((total, line) => total + system(line), 0)) }}</td>
                    <td class="px-2 py-2 text-center font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">
                        {{ qty(lines.reduce((total, line) => total + num(isCount ? line.counted_quantity : line.quantity), 0)) }}
                    </td>
                    <td v-if="isCount" class="px-2 py-2 text-end font-semibold text-brand-700 tabular-nums dark:text-brand-300" dir="ltr">{{ signed(groupDifference(lines)) }}</td>
                    <td v-if="canSeeCost" />
                    <td />
                </template>

                <template #cells="{ line, index }">
                    <td class="px-2 py-1">
                        <select v-if="!readonly" v-model="line.location_id" :class="[cellInput, 'w-36']">
                            <option :value="null">{{ isCount ? t('Whole warehouse') : t('Primary location') }}</option>
                            <option v-for="location in context.locations" :key="location.value" :value="location.value">{{ location.label }}</option>
                        </select>
                        <bdi v-else class="font-mono text-xs" dir="ltr">{{ line.location_code ?? '—' }}</bdi>
                    </td>
                    <td v-if="!isOpening" class="px-2 text-end text-gray-600 tabular-nums dark:text-gray-300" dir="ltr">{{ qty(system(line)) }}</td>
                    <template v-if="isCount">
                        <td class="px-2 py-1">
                            <input v-if="!readonly" v-model="line.counted_quantity" type="number" step="any" min="0" :class="[numberInput, 'w-24 text-center']" dir="ltr" />
                            <div v-else class="text-center font-semibold tabular-nums" dir="ltr">{{ qty(line.counted_quantity) }}</div>
                        </td>
                        <td class="px-2 text-end font-semibold tabular-nums" :class="tone(difference(line))" dir="ltr">{{ signed(difference(line)) }}</td>
                    </template>
                    <td v-else class="px-2 py-1">
                        <input v-if="!readonly" v-model="line.quantity" type="number" step="any" :class="[numberInput, 'w-24 text-center']" dir="ltr" />
                        <div v-else class="text-center font-semibold tabular-nums" dir="ltr">{{ qty(line.quantity) }}</div>
                    </td>
                    <td v-if="canSeeCost" class="px-2 py-1">
                        <input v-if="!readonly" v-model="line.unit_cost" type="number" step="any" min="0" :placeholder="t('Average')" :class="[numberInput, 'w-28 text-center']" dir="ltr" />
                        <div v-else class="text-center tabular-nums" dir="ltr">{{ qty(line.unit_cost) }}</div>
                    </td>
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
