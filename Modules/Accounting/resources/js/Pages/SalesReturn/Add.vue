<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { Info } from '@/lib/icons';
import { route } from '@/lib/route';

import { createGridApi, getJson } from '../parts/items-grid/api';
import type { Option } from '../parts/types';
import { DOCUMENT, type SalesReturnCreateForm } from './types';

/**
 * A new sales return: the customer and one of their posted invoices. Saving takes everything still
 * returnable of the invoice (with its sub-items); its page then enters what really returns.
 * Party, currency, rate, tax and prices come from the invoice (SalesReturnService).
 */
defineOptions({ layout: AdminLayout });

const props = defineProps<{ salesReturn: SalesReturnCreateForm; referenceNo: string; statusLabel: string; warehouseOptions: Option[] }>();

const form = useForm<SalesReturnCreateForm>(props.salesReturn);
const api = createGridApi(DOCUMENT, () => ({ partyId: form.client_id, exchangeRate: null }));

interface InvoiceDefaults {
    warehouse_id: number | null;
    currency: string | null;
    exchange_rate: string;
    tax: string | null;
    total_amount: string;
    invoice_date: string | null;
}

const invoice = ref<InvoiceDefaults | null>(null);

// Another customer: the invoice is chosen again.
watch(
    () => form.client_id,
    () => {
        form.sales_invoice_id = null;
        invoice.value = null;
    },
);

async function chooseInvoice(id: string | number | null | undefined): Promise<void> {
    form.sales_invoice_id = id ?? null;
    invoice.value = null;

    if (!id) return;

    try {
        invoice.value = await getJson<InvoiceDefaults>(route('accounting.sales-returns.invoice-defaults', { sales_invoice_id: id }));
        form.warehouse_id = invoice.value.warehouse_id;
    } catch {
        // Saving reads the invoice again.
    }
}
</script>

<template>
    <PageHeader :title="t('Add Sales Return')" :parents="[{ title: t('Sales Returns'), href: route('accounting.sales-returns.index') }]" />

    <form @submit.prevent="form.post(route('accounting.sales-returns.store'))">
        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2" :title="t('Sales Return Details')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextInput :model-value="referenceNo" :label="t('Reference No')" dir="ltr" disabled />
                    <TextInput :model-value="statusLabel" :label="t('Status')" disabled />
                    <TextInput v-model="form.return_date" type="date" :label="t('Return Date')" required :error="form.errors.return_date" />
                    <RemoteSelect v-model="form.client_id" :url="api.partiesUrl()" :label="t('Customer')" :placeholder="t('Select')" required :error="form.errors.client_id" />
                    <RemoteSelect
                        v-if="form.client_id"
                        :key="`invoice-${form.client_id}`"
                        :model-value="form.sales_invoice_id"
                        :url="route('accounting.sales-returns.invoices', { client_id: form.client_id })"
                        :label="t('Sales Invoice')"
                        :placeholder="t('Select')"
                        required
                        :error="form.errors.sales_invoice_id"
                        @update:model-value="chooseInvoice($event)"
                    />
                    <TextInput v-else :model-value="''" :label="t('Sales Invoice')" :placeholder="t('Select the customer first')" disabled :error="form.errors.sales_invoice_id" />
                    <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="warehouseOptions" :placeholder="t('Select')" :error="form.errors.warehouse_id" />
                    <template v-if="invoice">
                        <TextInput :model-value="invoice.currency ?? '-'" :label="t('Currency')" dir="ltr" disabled />
                        <TextInput :model-value="invoice.exchange_rate" :label="t('Exchange Rate')" dir="ltr" disabled />
                        <TextInput :model-value="invoice.tax ?? '-'" :label="t('Tax')" disabled />
                        <TextInput :model-value="invoice.total_amount" :label="t('Invoice Total')" dir="ltr" disabled />
                    </template>
                </div>
                <p class="mt-4 flex items-start gap-2 text-sm text-muted">
                    <Info class="mt-0.5 size-4 shrink-0" />
                    {{ t('The return takes everything still returnable of the invoice; enter what really returns on its page after saving.') }}
                </p>
            </Card>
            <Card :title="t('Notes')">
                <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="8" :error="form.errors.notes" />
            </Card>
        </div>
        <FormActions :processing="form.processing" :cancel-href="route('accounting.sales-returns.index')" :submit-label="t('Save')" />
    </form>
</template>
