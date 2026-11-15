<script setup lang="ts">
import { Link, router, type InertiaForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import Field from '@/Components/form/Field.vue';
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { createGridApi } from '../parts/items-grid/api';
import DocumentTotals from '../parts/items-grid/DocumentTotals.vue';
import ItemsGrid from '../parts/items-grid/ItemsGrid.vue';
import type { DocumentTotals as Totals } from '../parts/items-grid/types';
import { DOCUMENT, type PurchaseDocumentOptions, type PurchaseQuotationForm, type QuotedOrder } from './types';

/**
 * The purchase quotation form, shared by the add and edit pages: the purchase order it prices,
 * header, the order's lines in the items grid (items, sub-items and quantities come from the order;
 * only the supplier's price and discount are typed; weight items are priced per kilogram), totals
 * and notes. The server computes every amount again when saving.
 */
const props = defineProps<{
    form: InertiaForm<PurchaseQuotationForm>;
    options: PurchaseDocumentOptions;
    referenceNo: string;
    statusLabel: string;
    order: QuotedOrder | null;
    readonly?: boolean;
}>();

const totals = ref<Totals>({ sub_total: 0, items_discount: 0, document_discount: 0, tax_amount: 0, total_amount: 0 });
const api = createGridApi(DOCUMENT, () => ({ partyId: null, exchangeRate: props.form.exchange_rate }));

const taxRate = computed(() => props.options.taxOptions.find((tax) => String(tax.value) === String(props.form.tax_id))?.rate ?? '0');
const currency = computed(() => props.options.currencyOptions.find((option) => String(option.value) === String(props.form.currency_id))?.label ?? null);
const gridContext = computed(() => ({ partyId: null, exchangeRate: props.form.exchange_rate, documentDiscount: props.form.quotation_discount, taxRate: taxRate.value }));
const itemsError = computed(() => Object.entries(props.form.errors as Record<string, string>).find(([key]) => key === 'items' || key.startsWith('items.'))?.[1]);

/** Choosing an order opens the add page for it (or its quotation, when it already has one). */
function pickOrder(id: string | number | null | undefined): void {
    if (id) router.get(route('accounting.purchase-quotations.add'), { purchase_order: id });
}

watch(
    () => [props.form.currency_id, props.form.quotation_date],
    async () => {
        if (props.readonly) return;

        try {
            props.form.exchange_rate = (await api.exchangeRate(props.form.currency_id, props.form.quotation_date)).rate;
        } catch {
            // The rate stays as it is; saving computes it again.
        }
    },
);
</script>

<template>
    <div class="grid gap-4">
        <Card :title="t('Quotation Info')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <Field v-if="order" :label="t('Purchase Order')" required>
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.03]">
                            <span class="min-w-0">
                                <bdi class="font-semibold text-brand-600 dark:text-brand-400">{{ order.reference_no }}</bdi>
                                <span class="text-muted"> — {{ order.supplier ?? '-' }} · <span dir="ltr">{{ order.date }}</span></span>
                            </span>
                            <span class="flex items-center gap-2">
                                <Badge v-if="order.status" :tone="order.status.tone" dot>{{ order.status.label }}</Badge>
                                <Link v-if="can('Purchase Order View List')" :href="route('accounting.purchase-orders.edit', order.id)" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">{{ t('Open Order') }}</Link>
                            </span>
                        </div>
                    </Field>
                    <RemoteSelect
                        v-else
                        :model-value="form.purchase_order_id"
                        :url="route('accounting.purchase-quotations.orders')"
                        :label="t('Purchase Order')"
                        :placeholder="t('Search by order number or supplier')"
                        :hint="t('Open purchase orders without a quotation.')"
                        required
                        :error="form.errors.purchase_order_id"
                        @update:model-value="pickOrder"
                    />
                </div>
                <TextInput :model-value="referenceNo" :label="t('Reference No')" dir="ltr" disabled />
                <TextInput :model-value="statusLabel" :label="t('Status')" disabled />
                <TextInput v-model="form.quotation_date" type="date" :label="t('Quotation Date')" required :disabled="readonly" :error="form.errors.quotation_date" />
                <TextInput v-model="form.valid_until" type="date" :label="t('Valid Until')" :disabled="readonly" :error="form.errors.valid_until" />
                <TextInput v-model="form.supplier_reference" :label="t('Supplier Quotation No')" :disabled="readonly" :error="form.errors.supplier_reference" />
                <SelectInput v-model="form.tax_id" :label="t('Tax')" :options="options.taxOptions" :placeholder="t('No tax')" :disabled="readonly" :error="form.errors.tax_id" />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :disabled="readonly" :error="form.errors.currency_id" />
                <TextInput :model-value="form.exchange_rate ?? t('Missing')" :label="t('Exchange Rate')" dir="ltr" disabled />
            </div>
        </Card>

        <template v-if="order">
            <Card :title="t('Supplier Prices')" :description="t('The items and quantities come from the purchase order. Weight items are priced per kilogram.')">
                <ItemsGrid
                    v-model:items="form.items"
                    v-model:totals="totals"
                    :config="{ document: DOCUMENT, editable: false, pricesEditable: !readonly, actualWeightEditable: false, quantitiesEditable: false, showDiscount: true, barcode: false, groups: false }"
                    :context="gridContext"
                    :error="itemsError"
                />
            </Card>

            <div class="grid gap-4 lg:grid-cols-3">
                <DocumentTotals :totals="totals" :currency="currency">
                    <template #discount>
                        <TextInput
                            v-model="form.quotation_discount"
                            type="number"
                            step="0.0001"
                            min="0"
                            dir="ltr"
                            :label="t('Quotation Discount')"
                            :disabled="readonly"
                            :error="form.errors.quotation_discount"
                        />
                    </template>
                </DocumentTotals>
                <Card class="lg:col-span-2" :title="t('Notes')">
                    <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="9" :disabled="readonly" :error="form.errors.notes" />
                </Card>
            </div>
        </template>
    </div>
</template>
