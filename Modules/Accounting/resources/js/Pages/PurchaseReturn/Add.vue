<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { createGridApi } from '../parts/items-grid/api';
import { money } from '../parts/items-grid/useItemsGrid';
import type { Option } from '../parts/types';
import type { PurchaseDocumentOptions } from '../PurchaseQuotation/types';
import { DOCUMENT, type InvoiceDraft, type PurchaseReturnAddForm } from './types';

/**
 * A new purchase return: the supplier and one of their posted invoices. Saving takes everything
 * still returnable from the invoice (with its sub-items); the return's page then sets what is
 * actually returned. Currency, tax and prices always come from the invoice.
 */
defineOptions({ layout: AdminLayout });

const props = defineProps<
    PurchaseDocumentOptions & {
        purchaseReturn: PurchaseReturnAddForm;
        defaults: { currency_id: number | null; tax_id: number | null; exchange_rate: string };
        referenceNo: string;
        statusLabel: string;
    }
>();

const form = useForm<PurchaseReturnAddForm>(props.purchaseReturn);
const api = createGridApi(DOCUMENT, () => ({ partyId: form.client_id, exchangeRate: null }));
const invoices = shallowRef<Option[]>([]);
const draft = ref<InvoiceDraft | null>(null);
const invoiceError = ref<string | null>(null);

const currency = computed(() => props.currencyOptions.find((option) => String(option.value) === String(draft.value?.currency_id ?? props.defaults.currency_id))?.label ?? '-');
const tax = computed(() => props.taxOptions.find((option) => String(option.value) === String(draft.value?.tax_id ?? props.defaults.tax_id))?.label ?? t('No tax'));

type TotalKey = keyof InvoiceDraft['totals'];

const totalRows = computed<[TotalKey, string][]>(() => [
    ['sub_total', t('Subtotal')],
    ['items_discount', t('Items Discount')],
    ['invoice_discount', t('Invoice Discount')],
    ['tax_amount', t('Tax Amount')],
    ['total_amount', t('Total Amount')],
]);

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const body = (await response.json()) as T & { message?: string };

    if (!response.ok) throw new Error(body.message ?? `HTTP ${response.status}`);

    return body;
}

watch(
    () => form.client_id,
    async (supplier) => {
        form.purchase_invoice_id = null;
        invoices.value = [];

        if (!supplier) return;

        try {
            invoices.value = await getJson<Option[]>(route('accounting.purchase-returns.invoices', { client_id: supplier }));
        } catch {
            invoices.value = [];
        }
    },
);

watch(
    () => [form.purchase_invoice_id, form.return_date],
    async () => {
        invoiceError.value = null;

        if (!form.purchase_invoice_id) {
            draft.value = null;
            return;
        }

        try {
            draft.value = await getJson<InvoiceDraft>(route('accounting.purchase-returns.invoice-draft', { purchase_invoice_id: form.purchase_invoice_id, client_id: form.client_id || undefined, return_date: form.return_date }));
            form.warehouse_id ??= draft.value.warehouse_id;
        } catch (error) {
            invoiceError.value = (error as Error).message;
            draft.value = null;
        }
    },
);
</script>

<template>
    <PageHeader :title="t('Add Purchase Return')" :parents="[{ title: t('Purchase Returns'), href: route('accounting.purchase-returns.index') }]" />

    <form @submit.prevent="form.post(route('accounting.purchase-returns.store'))">
        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2" :title="t('Purchase Return Details')" :description="t('Everything still returnable from the invoice is taken; set the returned quantities on the return page.')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextInput :model-value="referenceNo" :label="t('Reference No')" dir="ltr" disabled />
                    <TextInput :model-value="statusLabel" :label="t('Status')" disabled />
                    <TextInput v-model="form.return_date" type="date" :label="t('Return Date')" required :error="form.errors.return_date" />
                    <RemoteSelect v-model="form.client_id" :url="api.partiesUrl()" :label="t('Supplier')" :placeholder="t('Select')" required :error="form.errors.client_id" />
                    <SelectInput
                        v-model="form.purchase_invoice_id"
                        :label="t('Purchase Invoice')"
                        :options="invoices"
                        :placeholder="form.client_id ? t('Select') : t('Select a supplier first')"
                        :disabled="!form.client_id"
                        required
                        :error="invoiceError ?? form.errors.purchase_invoice_id"
                    />
                    <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="warehouseOptions" :placeholder="t('Select')" :error="form.errors.warehouse_id" />
                    <TextInput :model-value="currency" :label="t('Currency')" disabled />
                    <TextInput :model-value="draft?.exchange_rate ?? defaults.exchange_rate" :label="t('Exchange Rate')" dir="ltr" disabled />
                    <TextInput :model-value="tax" :label="t('Tax')" disabled />
                </div>
            </Card>

            <div class="grid content-start gap-4">
                <Card v-if="draft" :title="t('Invoice Totals')">
                    <dl class="space-y-2 text-sm">
                        <div v-for="[key, label] in totalRows" :key="key" class="flex justify-between gap-3">
                            <dt class="text-muted">{{ label }}</dt>
                            <dd class="tabular-nums font-medium" dir="ltr">{{ money(draft.totals[key]) }}</dd>
                        </div>
                    </dl>
                </Card>
                <Card :title="t('Notes')">
                    <TextareaInput v-model="form.notes" :rows="6" :error="form.errors.notes" />
                </Card>
            </div>
        </div>

        <FormActions :processing="form.processing" :cancel-href="route('accounting.purchase-returns.index')" :submit-label="t('Save')" />
    </form>
</template>
