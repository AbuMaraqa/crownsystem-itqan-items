<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import Repeater from '@/Components/form/Repeater.vue';
import SelectInput, { type Option } from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

export interface JournalLineRow {
    account_id: number | string | null;
    branch_id: number | string | null;
    currency_id: number | string | null;
    exchange_rate: number | string;
    debit: number | string;
    credit: number | string;
    description: string;
}

export interface JournalEntryForm {
    entry_date: string;
    reference_no: string;
    description: string;
    lines: JournalLineRow[];
}

const props = defineProps<{
    form: InertiaForm<JournalEntryForm>;
    accounts: Option[];
    branches: Option[];
    currencies: Option[];
    defaultCurrencyId: number | null;
}>();

const newLine = (): JournalLineRow => ({
    account_id: null,
    branch_id: null,
    currency_id: props.defaultCurrencyId,
    exchange_rate: 1,
    debit: 0,
    credit: 0,
    description: '',
});

const number = (value: number | string | null | undefined): number => {
    const parsed = Number(String(value ?? '').replace(/[,\s]/g, ''));

    return Number.isFinite(parsed) ? parsed : 0;
};

const round4 = (value: number): number => Math.round(value * 10000) / 10000;

// The base-currency totals the server checks: each line is rounded after the exchange rate, as on save.
const totals = computed(() => {
    let debit = 0;
    let credit = 0;

    for (const line of props.form.lines) {
        const rate = number(line.exchange_rate) || 1;
        debit += round4(number(line.debit) * rate);
        credit += round4(number(line.credit) * rate);
    }

    debit = round4(debit);
    credit = round4(credit);

    return { debit, credit, difference: round4(debit - credit) };
});

const balanced = computed(() => Math.abs(totals.value.difference) <= 0.0001 && totals.value.debit > 0);
const format = (value: number): string => value.toLocaleString('en', { maximumFractionDigits: 4 });
</script>

<template>
    <div class="grid gap-4">
        <Card :title="t('Journal Entry Details')">
            <div class="grid gap-4 md:grid-cols-3">
                <TextInput v-model="form.entry_date" type="date" :label="t('Entry Date')" required :error="form.errors.entry_date" />
                <TextInput v-model="form.reference_no" :label="t('Reference No')" :maxlength="255" :error="form.errors.reference_no" />
                <TextareaInput v-model="form.description" class="md:col-span-3" :label="t('Description')" :error="form.errors.description" />
            </div>
        </Card>

        <Card :title="t('Journal Lines')">
            <template #actions>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span>{{ t('Debit Total') }}: <strong class="tabular-nums" dir="ltr">{{ format(totals.debit) }}</strong></span>
                    <span>{{ t('Credit Total') }}: <strong class="tabular-nums" dir="ltr">{{ format(totals.credit) }}</strong></span>
                    <Badge v-if="balanced" tone="success" dot>{{ t('Balanced') }}</Badge>
                    <Badge v-else tone="danger" dot>{{ t('Difference') }}: <bdi dir="ltr">{{ format(totals.difference) }}</bdi></Badge>
                </div>
            </template>

            <Repeater
                v-model="form.lines"
                name="lines"
                :errors="form.errors"
                :new-item="newLine"
                :add-label="t('Add Line')"
                :reorderable="false"
                :item-label="(_, index) => `#${index + 1}`"
                cloneable
            >
                <template #item="{ item, error }">
                    <div class="grid gap-3 md:grid-cols-6">
                        <MultiSelect v-model="item.account_id" single class="md:col-span-2" :label="t('Account')" required :options="accounts" :error="error('account_id')" />
                        <MultiSelect v-model="item.branch_id" single :label="t('Branch')" :options="branches" :error="error('branch_id')" />
                        <SelectInput v-model="item.currency_id" :label="t('Currency')" required :options="currencies" :error="error('currency_id')" />
                        <TextInput v-model="item.exchange_rate" type="number" step="any" min="0" :label="t('Exchange Rate')" required dir="ltr" :error="error('exchange_rate')" />
                        <TextInput v-model="item.debit" type="number" step="any" min="0" :label="t('Debit')" dir="ltr" :error="error('debit')" />
                        <TextInput v-model="item.credit" type="number" step="any" min="0" :label="t('Credit')" dir="ltr" :error="error('credit')" />
                        <TextInput v-model="item.description" class="md:col-span-5" :label="t('Description')" :error="error('description')" />
                    </div>
                </template>
            </Repeater>
        </Card>
    </div>
</template>
