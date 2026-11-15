<script setup lang="ts">
import { Link, type InertiaForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch } from 'vue';

import Field from '@/Components/form/Field.vue';
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { can, t } from '@/lib/i18n';
import { CircleAlert } from '@/lib/icons';
import { route } from '@/lib/route';

import { createGridApi } from '../parts/items-grid/api';
import DocumentTotals from '../parts/items-grid/DocumentTotals.vue';
import ItemsGrid from '../parts/items-grid/ItemsGrid.vue';
import type { DocumentTotals as Totals } from '../parts/items-grid/types';
import type { Option } from '../parts/types';
import { DOCUMENT, type PurchaseDocumentOptions, type PurchaseInvoiceForm, type PurchaseInvoiceRecord } from './types';

/**
 * The purchase invoice form, shared by the add and edit pages. A manual invoice takes any items
 * (main weight items with their sub-items, priced per kilogram); one from a purchase order carries
 * the order's lines with the approved quotation's prices: its items stay as in the order, while the
 * price, discount and scale weight are typed. The server computes every amount again when saving.
 */
const props = defineProps<{
    form: InertiaForm<PurchaseInvoiceForm>;
    options: PurchaseDocumentOptions;
    referenceNo: string;
    statusLabel: string;
    client?: Option | null;
    /** Edit page: the source documents and what can still be changed. */
    record?: PurchaseInvoiceRecord | null;
    readonly?: boolean;
}>();

const isAdd = computed(() => !props.record);
const itemsEditable = computed(() => (props.record ? props.record.items_editable : !props.form.purchase_order_id));
const pricesEditable = computed(() => !props.readonly);

const totals = ref<Totals>({ sub_total: 0, items_discount: 0, document_discount: 0, tax_amount: 0, total_amount: 0 });
const api = createGridApi(DOCUMENT, () => ({ partyId: props.form.client_id, exchangeRate: props.form.exchange_rate }));
const orderOptions = shallowRef<Option[]>([]);
const orderError = ref<string | null>(null);
const loadingOrder = ref(false);

const taxRate = computed(() => props.options.taxOptions.find((tax) => String(tax.value) === String(props.form.tax_id))?.rate ?? '0');
const currency = computed(() => props.options.currencyOptions.find((option) => String(option.value) === String(props.form.currency_id))?.label ?? null);
const gridContext = computed(() => ({
    partyId: props.form.client_id,
    exchangeRate: props.form.exchange_rate,
    warehouseId: props.form.warehouse_id,
    documentDiscount: props.form.invoice_discount,
    taxRate: taxRate.value,
}));
const itemsError = computed(() => Object.entries(props.form.errors as Record<string, string>).find(([key]) => key === 'items' || key.startsWith('items.'))?.[1]);

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const body = (await response.json()) as T & { message?: string };

    if (!response.ok) throw new Error(body.message ?? `HTTP ${response.status}`);

    return body;
}

/** The supplier's orders that can still be invoiced. */
async function loadOrders(): Promise<void> {
    if (!isAdd.value || !props.form.client_id) {
        orderOptions.value = [];
        return;
    }

    try {
        orderOptions.value = await getJson<Option[]>(route('accounting.purchase-invoices.orders', { client_id: props.form.client_id }));
    } catch {
        orderOptions.value = [];
    }
}

if (isAdd.value) {
    // The order's lines follow its supplier: a new supplier drops them; manual lines stay.
    watch(
        () => props.form.client_id,
        (supplier, previous) => {
            if (String(supplier ?? '') === String(previous ?? '')) return;

            if (props.form.purchase_order_id) {
                props.form.purchase_order_id = null;
                props.form.items = [];
            }

            loadOrders();
        },
    );

    /** Choosing an order fills the invoice from it (prices from its approved quotation). */
    watch(
        () => props.form.purchase_order_id,
        async (order) => {
            orderError.value = null;

            if (!order) {
                props.form.items = [];
                return;
            }

            loadingOrder.value = true;

            try {
                const draft = await getJson<Partial<PurchaseInvoiceForm>>(route('accounting.purchase-invoices.order-draft', { purchase_order_id: order, invoice_date: props.form.invoice_date }));
                Object.assign(props.form, draft);
            } catch (error) {
                orderError.value = (error as Error).message;
                props.form.purchase_order_id = null;
            } finally {
                loadingOrder.value = false;
            }
        },
    );
}

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
</script>

<template>
    <div class="grid gap-4">
        <Card :title="t('Purchase Invoice Details')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput :model-value="referenceNo" :label="t('Reference No')" dir="ltr" disabled />
                <TextInput :model-value="statusLabel" :label="t('Status')" disabled />
                <div class="sm:col-span-2">
                    <TextInput v-if="readonly" :model-value="client?.label ?? '-'" :label="t('Supplier')" disabled />
                    <RemoteSelect
                        v-else
                        v-model="form.client_id"
                        :url="api.partiesUrl()"
                        :initial="client ? [client] : []"
                        :label="t('Supplier')"
                        :placeholder="t('Select')"
                        required
                        :error="form.errors.client_id"
                    />
                </div>

                <SelectInput
                    v-if="isAdd"
                    v-model="form.purchase_order_id"
                    :label="t('Purchase Order')"
                    :options="orderOptions"
                    :placeholder="form.client_id ? t('Manual') : t('Select a supplier first')"
                    :disabled="!form.client_id || loadingOrder"
                    :error="orderError ?? form.errors.purchase_order_id"
                />
                <Field v-else :label="t('Purchase Order')">
                    <div class="flex h-10 items-center rounded-lg border border-line bg-gray-50 px-3 text-sm dark:bg-white/[0.03]">
                        <Link v-if="record?.purchase_order && can('Purchase Order View List')" :href="route('accounting.purchase-orders.edit', record.purchase_order.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400"><bdi>{{ record.purchase_order.reference_no }}</bdi></Link>
                        <bdi v-else-if="record?.purchase_order">{{ record.purchase_order.reference_no }}</bdi>
                        <span v-else class="text-muted">{{ t('Manual') }}</span>
                    </div>
                </Field>
                <TextInput v-if="record?.purchase_quotation" :model-value="record.purchase_quotation.reference_no" :label="t('Purchase Quotation')" dir="ltr" disabled />

                <TextInput v-model="form.invoice_date" type="date" :label="t('Invoice Date')" required :disabled="readonly" :error="form.errors.invoice_date" />
                <TextInput v-model="form.due_date" type="date" :label="t('Due Date')" :disabled="readonly" :error="form.errors.due_date" />
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="options.warehouseOptions" :placeholder="t('Select')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :disabled="readonly" :error="form.errors.currency_id" />
                <TextInput :model-value="form.exchange_rate ?? t('Missing')" :label="t('Exchange Rate')" dir="ltr" disabled :error="form.errors.exchange_rate" />
                <SelectInput v-model="form.tax_id" :label="t('Tax')" :options="options.taxOptions" :placeholder="t('No tax')" :disabled="readonly" :error="form.errors.tax_id" />
            </div>
        </Card>

        <Card :title="t('Purchase Invoice Items')">
            <!-- Add page with an order: its lines move to the invoice as they are. -->
            <div v-if="isAdd && form.purchase_order_id" class="flex items-center gap-2 rounded-lg border border-blue-light-200 bg-blue-light-50 px-4 py-3 text-sm text-blue-light-800 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-300">
                <CircleAlert class="size-5 shrink-0" />
                {{ t('The lines of the chosen order move to the invoice. Enter actual weights and prices after saving.') }}
                <span class="ms-auto font-medium">{{ form.items.length }} {{ t('Items') }}</span>
            </div>
            <!-- Kept mounted while hidden: it still adds up the order's lines for the totals. -->
            <ItemsGrid
                v-show="!(isAdd && form.purchase_order_id)"
                v-model:items="form.items"
                v-model:totals="totals"
                :config="{ document: DOCUMENT, editable: itemsEditable && !readonly, pricesEditable, actualWeightEditable: pricesEditable, showDiscount: true, groups: false }"
                :context="gridContext"
                :error="itemsError"
            />
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <DocumentTotals :totals="totals" :currency="currency">
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
