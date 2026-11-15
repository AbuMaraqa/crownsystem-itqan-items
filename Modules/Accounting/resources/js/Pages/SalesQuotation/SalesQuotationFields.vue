<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

import { createGridApi } from '../parts/items-grid/api';
import DocumentTotals from '../parts/items-grid/DocumentTotals.vue';
import ItemsGrid from '../parts/items-grid/ItemsGrid.vue';
import type { DocumentTotals as Totals } from '../parts/items-grid/types';
import type { Option } from '../parts/types';
import { DOCUMENT, type SalesQuotationForm, type SalesQuotationOptions } from './types';

/**
 * The sales quotation form, shared by the add and edit pages: header, the items grid (main weight
 * items with their sub-items, prices by party), totals and notes. Read only once the quotation is
 * converted or cancelled. The exchange rate follows the currency and date; the server computes it
 * again, with every amount, when saving.
 */
const props = defineProps<{
    form: InertiaForm<SalesQuotationForm>;
    options: SalesQuotationOptions;
    referenceNo: string;
    statusLabel: string;
    client?: Option | null;
    marginHint?: string | null;
    readonly?: boolean;
}>();

const totals = ref<Totals>({ sub_total: 0, items_discount: 0, document_discount: 0, tax_amount: 0, total_amount: 0 });
const hint = ref<string | null>(props.marginHint ?? null);

const api = createGridApi(DOCUMENT, () => ({ partyId: props.form.client_id, exchangeRate: props.form.exchange_rate }));

const taxRate = computed(() => props.options.taxOptions.find((tax) => String(tax.value) === String(props.form.tax_id))?.rate ?? '0');
const currency = computed(() => props.options.currencyOptions.find((option) => String(option.value) === String(props.form.currency_id))?.label ?? null);

const gridContext = computed(() => ({
    partyId: props.form.client_id,
    exchangeRate: props.form.exchange_rate,
    warehouseId: props.form.warehouse_id,
    documentDiscount: props.form.quotation_discount,
    taxRate: taxRate.value,
}));

/** The first error of the items, shown under the grid (it has no field per error). */
const itemsError = computed(() => Object.entries(props.form.errors as Record<string, string>).find(([key]) => key === 'items' || key.startsWith('items.'))?.[1]);

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
</script>

<template>
    <div class="grid gap-4">
        <Card :title="t('Sales Quotation Details')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TextInput :model-value="referenceNo" :label="t('Reference No')" dir="ltr" disabled />
                <TextInput :model-value="statusLabel" :label="t('Status')" disabled />
                <TextInput v-model="form.quotation_date" type="date" :label="t('Quotation Date')" required :disabled="readonly" :error="form.errors.quotation_date" />
                <TextInput v-model="form.valid_until" type="date" :label="t('Valid Until')" :disabled="readonly" :error="form.errors.valid_until" />
                <div class="sm:col-span-2">
                    <TextInput v-if="readonly" :model-value="client?.label ?? '-'" :label="t('Customer')" disabled />
                    <RemoteSelect
                        v-else
                        v-model="form.client_id"
                        :url="api.partiesUrl()"
                        :initial="client ? [client] : []"
                        :label="t('Customer')"
                        :placeholder="t('Select')"
                        :hint="hint ?? undefined"
                        required
                        :error="form.errors.client_id"
                    />
                </div>
                <SelectInput v-model="form.warehouse_id" :label="t('Warehouse')" :options="options.warehouseOptions" :placeholder="t('Select')" :disabled="readonly" :error="form.errors.warehouse_id" />
                <SelectInput v-model="form.tax_id" :label="t('Tax')" :options="options.taxOptions" :placeholder="t('Select')" :disabled="readonly" :error="form.errors.tax_id" />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :disabled="readonly" :error="form.errors.currency_id" />
                <TextInput :model-value="form.exchange_rate" :label="t('Exchange Rate')" dir="ltr" disabled :error="form.errors.exchange_rate" />
            </div>
        </Card>

        <Card :title="t('Sales Quotation Items')">
            <ItemsGrid
                v-model:items="form.items"
                v-model:totals="totals"
                :config="{ document: DOCUMENT, editable: !readonly, showDiscount: true }"
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
    </div>
</template>
