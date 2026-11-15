<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { createGridApi, getJson } from '../parts/items-grid/api';
import CustomerMaterials from '../parts/items-grid/CustomerMaterials.vue';
import DocumentTotals from '../parts/items-grid/DocumentTotals.vue';
import ItemsGrid from '../parts/items-grid/ItemsGrid.vue';
import ServiceLines from '../parts/items-grid/ServiceLines.vue';
import type { CustomerMaterial, DocumentTotals as Totals, GridRow, ServiceLine } from '../parts/items-grid/types';
import { documentWeight, num } from '../parts/items-grid/useItemsGrid';
import type { Option } from '../parts/types';
import { DIRECT, DOCUMENT, type SalesOrderForm, type SalesOrderOptions } from './types';

/**
 * The sales order form, shared by the add and edit pages: the header (customer above the lines,
 * because their prices come from it; the quotation it comes from; the fulfillment source), the
 * items grid, the service lines, the customer's materials, notes and totals. Read only once the
 * order is invoiced or cancelled. The server computes every amount again when saving.
 */
const props = defineProps<{
    form: InertiaForm<SalesOrderForm>;
    options: SalesOrderOptions;
    referenceNo: string;
    statusLabel: string;
    client?: Option | null;
    quotation?: Option | null;
    directSupplier?: Option | null;
    marginHint?: string | null;
    readonly?: boolean;
    /** The direct purchase order exists: the fulfillment source no longer changes. */
    fulfillmentLocked?: boolean;
    /** The add page chooses a quotation; the edit page shows the one it came from. */
    choosesQuotation?: boolean;
}>();

const totals = ref<Totals>({ sub_total: 0, items_discount: 0, document_discount: 0, tax_amount: 0, total_amount: 0 });
const hint = ref<string | null>(props.marginHint ?? null);
const clientOption = ref<Option | null>(props.client ?? null);
const quotationOption = ref<Option | null>(props.quotation ?? null);
const tab = ref('items');

const api = createGridApi(DOCUMENT, () => ({ partyId: props.form.client_id, exchangeRate: props.form.exchange_rate }));

const taxRate = computed(() => props.options.taxOptions.find((tax) => String(tax.value) === String(props.form.tax_id))?.rate ?? '0');
const currency = computed(() => props.options.currencyOptions.find((option) => String(option.value) === String(props.form.currency_id))?.label ?? null);
const isDirect = computed(() => props.form.fulfillment_type === DIRECT);
const fulfillmentReadonly = computed(() => props.readonly || props.fulfillmentLocked);

const gridContext = computed(() => ({
    partyId: props.form.client_id,
    exchangeRate: props.form.exchange_rate,
    warehouseId: props.form.warehouse_id,
    documentDiscount: props.form.order_discount,
    taxRate: taxRate.value,
    extraLines: props.form.services,
}));

const weight = computed(() =>
    documentWeight(
        props.form.customer_materials.reduce((sum, material) => sum + num(material.weight), 0),
        props.form.items,
    ),
);

const errors = computed(() => props.form.errors as Record<string, string>);
const firstError = (prefix: string): string | undefined => Object.entries(errors.value).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1];

const tabs = computed(() => [
    { value: 'items', label: t('Items List'), hasError: Boolean(firstError('items')) },
    { value: 'services', label: `${t('Services')}${countLabel(props.form.services.filter((line) => line.product_id).length)}`, hasError: Boolean(firstError('services')) },
    {
        value: 'materials',
        label: `${t('Customer Materials')}${countLabel(props.form.customer_materials.filter((line) => line.product_id || line.description).length)}`,
        hasError: Boolean(firstError('customer_materials')),
    },
    { value: 'notes', label: t('Notes'), hasError: Boolean(errors.value.notes) },
]);

function countLabel(count: number): string {
    return count ? ` (${count})` : '';
}

watch(
    () => [props.form.currency_id, props.form.order_date],
    async () => {
        if (props.readonly) return;

        try {
            props.form.exchange_rate = (await api.exchangeRate(props.form.currency_id, props.form.order_date)).rate;
        } catch {
            // The rate stays as it is; saving computes it again.
        }
    },
);

watch(
    () => props.form.client_id,
    async (party) => {
        try {
            hint.value = (await api.partyHint(party)).hint;
        } catch {
            hint.value = null;
        }
    },
);

watch(
    () => props.form.fulfillment_type,
    (type) => {
        if (type !== DIRECT) props.form.direct_supplier_id = null;
    },
);

interface QuotationDefaults {
    client: Option | null;
    client_id: number;
    branch_id: number | null;
    warehouse_id: number | null;
    currency_id: number | null;
    tax_id: number | null;
    exchange_rate: string | null;
    order_discount: string;
    items: GridRow[];
    services: ServiceLine[];
}

/** A chosen quotation fills the header, the grid and the services; clearing it empties the lines. */
async function chooseQuotation(id: string | number | null | undefined): Promise<void> {
    props.form.sales_quotation_id = id ?? null;

    if (!id) {
        props.form.items = [];
        props.form.services = [];

        return;
    }

    try {
        const defaults = await getJson<QuotationDefaults>(route('accounting.sales-orders.quotation-defaults', { sales_quotation_id: id, order_date: props.form.order_date }));
        const { client, ...values } = defaults;

        clientOption.value = client;
        Object.assign(props.form, values);
    } catch {
        // The quotation stays chosen; saving reads it again.
    }
}

function setMaterials(lines: CustomerMaterial[]): void {
    props.form.customer_materials = lines;
}
</script>

<template>
    <div class="grid gap-4">
        <Card :title="t('Sales Order Details')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <TextInput v-if="readonly" :model-value="clientOption?.label ?? '-'" :label="t('Customer')" disabled />
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

                <div v-if="choosesQuotation && !readonly" class="sm:col-span-2">
                    <RemoteSelect
                        :key="`quotation-${form.client_id ?? ''}`"
                        :model-value="form.sales_quotation_id"
                        :url="route('accounting.sales-orders.quotations', { client_id: form.client_id || undefined })"
                        :initial="quotationOption ? [quotationOption] : []"
                        :label="t('Sales Quotation')"
                        :placeholder="t('Manual')"
                        :error="form.errors.sales_quotation_id"
                        @update:model-value="chooseQuotation($event)"
                        @picked="quotationOption = $event"
                    />
                </div>
                <TextInput v-else :model-value="quotation?.label ?? t('Manual')" :label="t('Sales Quotation')" dir="ltr" disabled />

                <TextInput v-model="form.order_date" type="date" :label="t('Order Date')" required :disabled="readonly" :error="form.errors.order_date" />
                <TextInput v-model="form.expected_delivery_date" type="date" :label="t('Expected Delivery Date')" :disabled="readonly" :error="form.errors.expected_delivery_date" />
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="options.warehouseOptions" :placeholder="t('Select')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <SelectInput
                    v-model="form.fulfillment_type"
                    :label="t('Fulfillment Source')"
                    :options="options.fulfillmentOptions"
                    :disabled="fulfillmentReadonly"
                    :hint="isDirect ? t('Once confirmed, the order is sent to the purchasing department to create a purchase order shipped directly to the customer.') : undefined"
                    :error="form.errors.fulfillment_type"
                />
                <template v-if="isDirect">
                    <TextInput v-if="fulfillmentReadonly" :model-value="directSupplier?.label ?? t('Purchasing will choose the supplier')" :label="t('Suggested Supplier')" disabled />
                    <RemoteSelect
                        v-else
                        v-model="form.direct_supplier_id"
                        :url="route('accounting.sales-orders.suppliers')"
                        :initial="directSupplier ? [directSupplier] : []"
                        :label="t('Suggested Supplier')"
                        :placeholder="t('Purchasing will choose the supplier')"
                        :error="form.errors.direct_supplier_id"
                    />
                </template>
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :disabled="readonly" :error="form.errors.currency_id" />
                <TextInput :model-value="form.exchange_rate" :label="t('Exchange Rate')" dir="ltr" disabled :error="form.errors.exchange_rate" />
                <SelectInput v-model="form.tax_id" :label="t('Tax')" :options="options.taxOptions" :placeholder="t('Select')" :disabled="readonly" :error="form.errors.tax_id" />
            </div>
        </Card>

        <Card>
            <Tabs v-model="tab" :tabs="tabs">
                <template #items>
                    <ItemsGrid
                        v-model:items="form.items"
                        v-model:totals="totals"
                        :config="{ document: DOCUMENT, editable: !readonly }"
                        :context="gridContext"
                        :error="firstError('items')"
                    />
                </template>
                <template #services>
                    <ServiceLines
                        v-model:lines="form.services"
                        :document="DOCUMENT"
                        :editable="!readonly"
                        :context="gridContext"
                        :options="options.serviceOptions"
                        :document-weight="weight"
                        :error="firstError('services')"
                    />
                </template>
                <template #materials>
                    <CustomerMaterials :lines="form.customer_materials" :document="DOCUMENT" :editable="!readonly" :context="gridContext" :errors="errors" @update:lines="setMaterials" />
                </template>
                <template #notes>
                    <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="6" :disabled="readonly" :error="form.errors.notes" />
                </template>
            </Tabs>
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <DocumentTotals class="lg:col-start-3" :totals="totals" :currency="currency">
                <template #discount>
                    <TextInput
                        v-model="form.order_discount"
                        type="number"
                        step="0.0001"
                        min="0"
                        dir="ltr"
                        :label="t('Order Discount')"
                        :disabled="readonly"
                        :error="form.errors.order_discount"
                    />
                </template>
            </DocumentTotals>
        </div>
    </div>
</template>
