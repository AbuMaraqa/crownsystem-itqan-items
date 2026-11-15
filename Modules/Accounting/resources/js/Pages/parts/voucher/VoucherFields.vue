<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { Option } from '../types';
import type { VoucherForm, VoucherFormOptions, VoucherKind } from './types';

/**
 * The voucher form: shared by the receipt and payment pages, editable while the voucher is a
 * draft, read only after. The exchange rate follows the currency and the date (the server
 * computes it again when saving).
 */
const props = defineProps<{
    form: InertiaForm<VoucherForm>;
    options: VoucherFormOptions;
    kind: VoucherKind;
    referenceNo: string;
    statusLabel: string;
    /** The saved party, so its name shows before any search. */
    client?: Option | null;
    readonly?: boolean;
}>();

const isCheque = computed(() => Number(props.form.payment_method) === props.options.chequeMethod);

async function refreshRate(): Promise<void> {
    if (props.readonly) return;

    const url = route(`${props.kind.routes}.exchange-rate`, { currency_id: props.form.currency_id, date: props.form.voucher_date });

    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;

        const { rate } = (await response.json()) as { rate: string | null };
        props.form.exchange_rate = rate;
    } catch {
        // The rate stays as it is; saving computes it again.
    }
}

watch(() => [props.form.currency_id, props.form.voucher_date], refreshRate);
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-3">
        <Card class="lg:col-span-2" :title="t(kind.details)">
            <div class="grid gap-4 md:grid-cols-2">
                <TextInput :model-value="referenceNo" :label="t('Reference No')" dir="ltr" disabled />
                <TextInput :model-value="statusLabel" :label="t('Status')" disabled />
                <TextInput v-model="form.voucher_date" type="date" :label="t('Voucher Date')" required :disabled="readonly" :error="form.errors.voucher_date" />
                <TextInput v-if="readonly" :model-value="client?.label ?? '-'" :label="t('Customer / Supplier')" disabled />
                <RemoteSelect
                    v-else
                    v-model="form.client_id"
                    :url="route(`${kind.routes}.parties`)"
                    :initial="client ? [client] : []"
                    :label="t('Customer / Supplier')"
                    :placeholder="t('Select')"
                    required
                    :error="form.errors.client_id"
                />
                <SelectInput v-if="readonly" :model-value="form.account_id" :label="t(kind.account)" :options="options.accountOptions" disabled />
                <MultiSelect v-else v-model="form.account_id" single :label="t(kind.account)" :placeholder="t('Select')" required :options="options.accountOptions" :error="form.errors.account_id" />
                <SelectInput v-model="form.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :disabled="readonly" :error="form.errors.currency_id" />
                <TextInput :model-value="form.exchange_rate" :label="t('Exchange Rate')" dir="ltr" disabled :error="form.errors.exchange_rate" />
                <SelectInput v-model="form.payment_method" :label="t('Payment Method')" required :options="options.paymentMethods" :disabled="readonly" :error="form.errors.payment_method" />
                <TextInput v-model="form.amount" type="number" step="0.0001" min="0.0001" :label="t('Amount')" dir="ltr" required :disabled="readonly" :error="form.errors.amount" />
                <template v-if="isCheque">
                    <TextInput v-model="form.cheque_number" :label="t('Cheque Number')" dir="ltr" required :disabled="readonly" :maxlength="255" :error="form.errors.cheque_number" />
                    <TextInput v-model="form.cheque_bank_name" :label="t('Cheque Bank')" :disabled="readonly" :maxlength="255" :error="form.errors.cheque_bank_name" />
                    <TextInput v-model="form.cheque_date" type="date" :label="t('Cheque Due Date')" required :disabled="readonly" :error="form.errors.cheque_date" />
                </template>
            </div>
        </Card>
        <Card :title="t('Notes')">
            <TextareaInput v-model="form.notes" :label="t('Notes')" :rows="8" :disabled="readonly" :error="form.errors.notes" />
        </Card>
    </div>
</template>
