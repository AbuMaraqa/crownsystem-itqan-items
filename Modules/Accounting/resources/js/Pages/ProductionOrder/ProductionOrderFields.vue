<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { Returns } from '@/lib/icons';
import { route } from '@/lib/route';

import { getJson, postJson, withQuery } from '../parts/http';
import type { Option } from '../parts/types';
import CostSummary from './parts/CostSummary.vue';
import LaborLines from './parts/LaborLines.vue';
import MaterialLines from './parts/MaterialLines.vue';
import ServiceLines from './parts/ServiceLines.vue';
import type { CostPreview, MaterialLine, ProductionContext, ProductionOptions, ProductionOrderForm, ServiceLine } from './types';

/**
 * The production order form, shared by the add and edit pages: the order's data, then three tabs
 * of cost lines (items, wages, services) and the cost summary. The summary and each line's total
 * come from the server (cost-preview), with the same calculation as saving. Read only once the
 * order is completed or cancelled.
 */
const props = defineProps<{
    form: InertiaForm<ProductionOrderForm>;
    options: ProductionOptions;
    context: ProductionContext;
    referenceNo: string;
    statusLabel: string;
    readonly?: boolean;
}>();

const editable = computed(() => !props.readonly);
const clientLabel = ref<string | null>(props.context.client_label);
const salesOrder = ref<Option | null>(props.context.sales_order);
const tab = ref('materials');

interface SalesOrderDefaults {
    sales_order: Option;
    client_label: string | null;
    currency_id: number | null;
    warehouse_id: number | null;
    due_date: string | null;
    materials: MaterialLine[];
    services: ServiceLine[];
}

const errors = computed(() => props.form.errors as Record<string, string>);
const currency = computed(() => props.options.currencyOptions.find((option) => String(option.value) === String(props.form.currency_id))?.label ?? null);

const filled = (lines: object[], keys: string[]): number => lines.filter((line) => keys.some((key) => !!(line as Record<string, unknown>)[key])).length;
const counts = computed(() => ({
    materials: filled(props.form.materials, ['product_id', 'description']),
    labor: filled(props.form.labor, ['worker_id', 'worker_name', 'description']),
    services: filled(props.form.services, ['product_id', 'description']),
}));

const tabLabel = (label: string, count: number): string => (count ? `${label} (${count})` : label);
const hasError = (prefix: string): boolean => Object.keys(errors.value).some((key) => key.startsWith(`${prefix}.`));

const tabs = computed(() => [
    { value: 'materials', label: tabLabel(t('Items'), counts.value.materials), hasError: hasError('materials') },
    { value: 'labor', label: tabLabel(t('Wages'), counts.value.labor), hasError: hasError('labor') },
    { value: 'services', label: tabLabel(t('Services'), counts.value.services), hasError: hasError('services') },
]);

/* ---------------- Sales order ---------------- */

async function salesOrderDefaults(id: number | string): Promise<SalesOrderDefaults | null> {
    try {
        return await getJson<SalesOrderDefaults | null>(withQuery(route('accounting.production-orders.sales-order'), { sales_order_id: id }));
    } catch {
        return null;
    }
}

/**
 * The client and currency follow the chosen sales order; the warehouse and delivery date are
 * suggested when empty, and its lines when the order has none yet (nothing the user typed is lost).
 */
async function salesOrderPicked(option: Option): Promise<void> {
    const defaults = await salesOrderDefaults(option.value);

    salesOrder.value = defaults?.sales_order ?? option;
    clientLabel.value = defaults?.client_label ?? null;

    if (!defaults) return;

    props.form.currency_id = defaults.currency_id;
    if (!props.form.warehouse_id) props.form.warehouse_id = defaults.warehouse_id;
    if (!props.form.due_date && defaults.due_date) props.form.due_date = defaults.due_date;

    if (counts.value.materials === 0 && counts.value.services === 0) {
        props.form.materials = defaults.materials;
        props.form.services = defaults.services;
    }
}

watch(
    () => props.form.sales_order_id,
    (id) => {
        if (!id) {
            salesOrder.value = null;
            clientLabel.value = null;
        }
    },
);

/** Replaces the items and services with the sales order's lines; the wages stay. */
async function loadSalesOrderLines(): Promise<void> {
    if (!props.form.sales_order_id || !(await confirm({ title: t('Replace the items and services with the sales order lines?'), confirmLabel: t('Load Lines From Sales Order'), tone: 'primary' }))) return;

    const defaults = await salesOrderDefaults(props.form.sales_order_id);

    if (defaults) {
        props.form.materials = defaults.materials;
        props.form.services = defaults.services;
    }
}

/* ---------------- Cost preview ---------------- */

const preview = ref<CostPreview>({
    summary: { materials_cost: 0, labor_cost: 0, services_cost: 0, total_cost: 0, unit_cost: 0, sales_amount: props.context.sales_amount, margin: 0, margin_rate: null },
    materials_weight: 0,
    lines: { materials: [], labor: [], services: [] },
});
const previewing = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | undefined;

async function refreshPreview(): Promise<void> {
    request?.abort();
    request = new AbortController();
    previewing.value = true;

    try {
        preview.value = await postJson<CostPreview>(
            route('accounting.production-orders.cost-preview'),
            {
                sales_order_id: props.form.sales_order_id,
                produced_quantity: props.form.produced_quantity,
                materials: props.form.materials,
                labor: props.form.labor,
                services: props.form.services,
            },
            request.signal,
        );
        previewing.value = false;
    } catch {
        // A newer preview replaced this one, or the network failed: the last figures stay.
    }
}

watch(
    () => [props.form.sales_order_id, props.form.produced_quantity, props.form.materials, props.form.labor, props.form.services],
    () => {
        clearTimeout(timer);
        timer = setTimeout(refreshPreview, 300);
    },
    { deep: true, immediate: true },
);

onBeforeUnmount(() => {
    clearTimeout(timer);
    request?.abort();
});
</script>

<template>
    <div class="grid gap-4">
        <Card :title="t('Production Order Details')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <TextInput :model-value="referenceNo" :label="t('Reference No')" dir="ltr" disabled />
                <TextInput :model-value="statusLabel" :label="t('Status')" disabled />
                <div class="sm:col-span-2">
                    <RemoteSelect
                        v-if="editable"
                        v-model="form.sales_order_id"
                        :url="route('accounting.production-orders.sales-orders')"
                        :initial="salesOrder ? [salesOrder] : []"
                        :label="t('Sales Order')"
                        :placeholder="t('Select')"
                        :error="form.errors.sales_order_id"
                        @picked="salesOrderPicked"
                    />
                    <TextInput v-else :model-value="salesOrder?.label ?? '-'" :label="t('Sales Order')" disabled />
                </div>
                <div class="sm:col-span-2">
                    <TextInput :model-value="clientLabel ?? '-'" :label="t('Client')" disabled />
                </div>
                <TextInput v-model="form.order_date" type="date" :label="t('Order Date')" required :disabled="readonly" :error="form.errors.order_date" />
                <TextInput v-model="form.due_date" type="date" :label="t('Due Date')" :disabled="readonly" :error="form.errors.due_date" />
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="options.warehouseOptions" :placeholder="t('Select')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <SelectInput
                    v-model="form.currency_id"
                    :label="t('Currency')"
                    required
                    :options="options.currencyOptions"
                    :disabled="readonly || !!form.sales_order_id"
                    :hint="form.sales_order_id ? t('Follows the sales order currency.') : undefined"
                    :error="form.errors.currency_id"
                />
                <TextInput
                    v-model="form.produced_quantity"
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    dir="ltr"
                    :label="t('Produced Quantity')"
                    :hint="t('Used to calculate the unit cost.')"
                    required
                    :disabled="readonly"
                    :error="form.errors.produced_quantity"
                />
                <div class="sm:col-span-2 lg:col-span-6">
                    <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="2" :disabled="readonly" :error="form.errors.notes" />
                </div>
            </div>
        </Card>

        <Card :title="t('Production Costs')">
            <template v-if="editable && form.sales_order_id" #actions>
                <Button size="sm" @click="loadSalesOrderLines"><Returns />{{ t('Load Lines From Sales Order') }}</Button>
            </template>
            <Tabs v-model="tab" :tabs="tabs">
                <template #materials>
                    <MaterialLines v-model="form.materials" :totals="preview.lines.materials" :editable="editable" :errors="errors" />
                </template>
                <template #labor>
                    <LaborLines v-model="form.labor" :totals="preview.lines.labor" :editable="editable" :errors="errors" :basis-options="options.laborBasisOptions" />
                </template>
                <template #services>
                    <ServiceLines
                        v-model="form.services"
                        :totals="preview.lines.services"
                        :editable="editable"
                        :errors="errors"
                        :materials="form.materials"
                        :materials-weight="preview.materials_weight"
                        :cost-source-options="options.costSourceOptions"
                        :pricing-basis-options="options.pricingBasisOptions"
                    />
                </template>
            </Tabs>
        </Card>

        <CostSummary :summary="preview.summary" :currency="currency" :loading="previewing" />
    </div>
</template>
