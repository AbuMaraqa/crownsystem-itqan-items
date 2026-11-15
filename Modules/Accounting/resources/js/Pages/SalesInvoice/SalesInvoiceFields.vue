<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { Info } from '@/lib/icons';
import { route } from '@/lib/route';

import { createGridApi, getJson } from '../parts/items-grid/api';
import DocumentTotals from '../parts/items-grid/DocumentTotals.vue';
import ItemsGrid from '../parts/items-grid/ItemsGrid.vue';
import ServiceLines from '../parts/items-grid/ServiceLines.vue';
import type { DocumentTotals as Totals } from '../parts/items-grid/types';
import { documentWeight } from '../parts/items-grid/useItemsGrid';
import type { Option } from '../parts/types';
import { DOCUMENT, type SalesInvoiceForm, type SalesInvoiceOptions } from './types';

/**
 * The sales invoice form, shared by the add and edit pages. A manual invoice enters its lines in
 * the items grid; an invoice from a sales order keeps the order's items and sub-items (what left
 * the warehouse) and takes the actual weight, prices and discounts. On the add page, choosing an
 * order moves all its lines when saving. Read only once posted or cancelled, or without the
 * update permission. The server computes every amount again.
 */
const props = defineProps<{
    form: InertiaForm<SalesInvoiceForm>;
    options: SalesInvoiceOptions;
    referenceNo: string;
    statusLabel: string;
    client?: Option | null;
    salesOrder?: Option | null;
    marginHint?: string | null;
    readonly?: boolean;
    /** Edit page: the invoice comes from an order, so its items, party and currency are fixed. */
    fromOrder?: boolean;
    /** Add page: an order can be chosen. */
    choosesOrder?: boolean;
    /** kg of the source order's customer materials, for a per-kg service. */
    materialsWeight?: number;
}>();

const totals = ref<Totals>({ sub_total: 0, items_discount: 0, document_discount: 0, tax_amount: 0, total_amount: 0 });
const orderTotals = ref<Totals | null>(null);
const hint = ref<string | null>(props.marginHint ?? null);
const clientOption = ref<Option | null>(props.client ?? null);
const orderClientId = ref<string | number | null>(null);
const orderOption = ref<Option | null>(props.salesOrder ?? null);

const api = createGridApi(DOCUMENT, () => ({ partyId: props.form.client_id, exchangeRate: props.form.exchange_rate }));

const taxRate = computed(() => props.options.taxOptions.find((tax) => String(tax.value) === String(props.form.tax_id))?.rate ?? '0');
const currency = computed(() => props.options.currencyOptions.find((option) => String(option.value) === String(props.form.currency_id))?.label ?? null);
/** Add page with an order chosen: its lines move when saving, so the grid is not shown. */
const orderChosen = computed(() => Boolean(props.choosesOrder && props.form.sales_order_id));
const headerLocked = computed(() => props.readonly || props.fromOrder);

const gridContext = computed(() => ({
    partyId: props.form.client_id,
    exchangeRate: props.form.exchange_rate,
    warehouseId: props.form.warehouse_id,
    documentDiscount: props.form.invoice_discount,
    taxRate: taxRate.value,
    extraLines: props.form.services,
}));

const gridConfig = computed(() => ({
    document: DOCUMENT,
    editable: !props.readonly && !props.fromOrder,
    pricesEditable: !props.readonly,
    actualWeightEditable: !props.readonly,
    showDiscount: true,
}));

const weight = computed(() => documentWeight(props.materialsWeight ?? 0, props.form.items));

const errors = computed(() => props.form.errors as Record<string, string>);
const firstError = (prefix: string): string | undefined => Object.entries(errors.value).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1];

watch(
    () => [props.form.currency_id, props.form.invoice_date],
    async () => {
        if (props.readonly) return;

        try {
            props.form.exchange_rate = (await api.exchangeRate(props.form.currency_id, props.form.invoice_date)).rate;
        } catch {
            // The rate stays as it is; saving computes it again.
        }
    },
);

watch(
    () => props.form.client_id,
    async (party) => {
        // The order's lines follow its party: another party drops the chosen order.
        if (props.choosesOrder && props.form.sales_order_id && String(party ?? '') !== String(orderClientId.value ?? '')) {
            props.form.sales_order_id = null;
            orderTotals.value = null;
        }

        try {
            hint.value = (await api.partyHint(party)).hint;
        } catch {
            hint.value = null;
        }
    },
);

interface OrderDefaults {
    client: Option | null;
    client_id: number;
    branch_id: number | null;
    warehouse_id: number | null;
    currency_id: number | null;
    tax_id: number | null;
    exchange_rate: string | null;
    invoice_discount: string;
    totals: Totals;
}

async function chooseOrder(id: string | number | null | undefined): Promise<void> {
    props.form.sales_order_id = id ?? null;
    props.form.items = [];
    props.form.services = [];
    orderTotals.value = null;

    if (!id) return;

    try {
        const { client, totals: values, ...header } = await getJson<OrderDefaults & { sales_order_id: number }>(
            route('accounting.sales-invoices.order-defaults', { sales_order_id: id, invoice_date: props.form.invoice_date }),
        );

        orderClientId.value = header.client_id;
        clientOption.value = client;
        Object.assign(props.form, header);
        orderTotals.value = values;
    } catch {
        // The order stays chosen; saving reads it again.
    }
}
</script>

<template>
    <div class="grid gap-4">
        <Card :title="t('Sales Invoice Details')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <TextInput v-if="headerLocked" :model-value="clientOption?.label ?? '-'" :label="t('Customer')" disabled />
                    <RemoteSelect
                        v-else
                        :key="`client-${clientOption?.value ?? ''}`"
                        v-model="form.client_id"
                        :url="api.partiesUrl()"
                        :initial="clientOption ? [clientOption] : []"
                        :label="t('Customer')"
                        :placeholder="t('Select')"
                        :hint="hint ?? undefined"
                        required
                        :error="form.errors.client_id"
                        @picked="clientOption = $event"
                    />
                </div>
                <TextInput :model-value="referenceNo" :label="t('Reference No')" dir="ltr" disabled />
                <TextInput :model-value="statusLabel" :label="t('Status')" disabled />

                <div v-if="choosesOrder && !readonly" class="sm:col-span-2">
                    <RemoteSelect
                        :key="`order-${form.client_id ?? ''}`"
                        :model-value="form.sales_order_id"
                        :url="route('accounting.sales-invoices.orders', { client_id: form.client_id || undefined })"
                        :initial="orderOption ? [orderOption] : []"
                        :label="t('Sales Order')"
                        :placeholder="t('Manual')"
                        :error="form.errors.sales_order_id"
                        @update:model-value="chooseOrder($event)"
                        @picked="orderOption = $event"
                    />
                </div>
                <TextInput v-else :model-value="salesOrder?.label ?? t('Manual')" :label="t('Sales Order')" dir="ltr" disabled />

                <TextInput v-model="form.invoice_date" type="date" :label="t('Invoice Date')" required :disabled="readonly" :error="form.errors.invoice_date" />
                <TextInput v-model="form.due_date" type="date" :label="t('Due Date')" :disabled="readonly" :error="form.errors.due_date" />
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="options.warehouseOptions" :placeholder="t('Select')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :disabled="headerLocked" :error="form.errors.currency_id" />
                <TextInput :model-value="form.exchange_rate" :label="t('Exchange Rate')" dir="ltr" disabled :error="form.errors.exchange_rate" />
                <SelectInput v-model="form.tax_id" :label="t('Tax')" :options="options.taxOptions" :placeholder="t('Select')" :disabled="readonly" :error="form.errors.tax_id" />
            </div>
        </Card>

        <Card :title="t('Sales Invoice Items')">
            <p
                v-if="orderChosen"
                class="flex items-start gap-2 rounded-lg border border-blue-light-200 bg-blue-light-50 px-3 py-2 text-sm text-blue-light-800 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-300"
            >
                <Info class="mt-0.5 size-4 shrink-0" />
                {{ t('The lines of the chosen order move to the invoice. Enter actual weights and prices after saving.') }}
            </p>
            <ItemsGrid v-else v-model:items="form.items" v-model:totals="totals" :config="gridConfig" :context="gridContext" :error="firstError('items')" />
        </Card>

        <Card v-if="!orderChosen" :title="t('Services')">
            <ServiceLines
                v-model:lines="form.services"
                :document="DOCUMENT"
                :editable="!readonly"
                :context="gridContext"
                :options="options.serviceOptions"
                :document-weight="weight"
                :error="firstError('services')"
            />
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <DocumentTotals :totals="orderChosen && orderTotals ? orderTotals : totals" :currency="currency">
                <template #discount>
                    <TextInput
                        v-model="form.invoice_discount"
                        type="number"
                        step="0.0001"
                        min="0"
                        dir="ltr"
                        :label="t('Invoice Discount')"
                        :disabled="readonly"
                        :error="form.errors.invoice_discount"
                    />
                </template>
            </DocumentTotals>
            <Card class="lg:col-span-2" :title="t('Notes')">
                <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="9" :disabled="readonly" :error="form.errors.notes" />
            </Card>
        </div>
    </div>
</template>
