<script setup lang="ts">
import { ref } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';

import type { StatusBadge } from '../../parts/statusBadge';
import FileField from '../parts/FileField.vue';
import RowActions from '../parts/RowActions.vue';
import TabTable from '../parts/TabTable.vue';
import { useTabForm } from '../parts/useTabForm';
import type { FileRef, TabOptions, TabRecord } from '../types';

/** The order's financial file: cash payments and bank credits, each with its status and documents. */
const props = defineProps<{ orderId: number; currencyId: number | null; payments: TabRecord[]; bankCredits: TabRecord[]; options: TabOptions }>();

const pending = (): number | null => (props.options.financialStatusOptions[0] ? Number(props.options.financialStatusOptions[0].value) : null);

interface PaymentForm {
    payment_type: number | null;
    status: number | null;
    amount: string | null;
    percentage: string | null;
    due_date: string | null;
    currency_id: number | null;
    notes: string | null;
    attachment: File | null;
    remove_attachment: boolean;
}

interface CreditForm {
    credit_type: number | null;
    bank_name: string | null;
    credit_amount: string | null;
    duration_days: number | string | null;
    due_date: string | null;
    status: number | null;
    currency_id: number | null;
    notes: string | null;
    attachment: File | null;
    remove_attachment: boolean;
}

const payment = useTabForm<PaymentForm>(() => props.orderId, 'payments', () => ({
    payment_type: null,
    status: pending(),
    amount: null,
    percentage: null,
    due_date: null,
    currency_id: props.currencyId,
    notes: null,
    attachment: null,
    remove_attachment: false,
}));

const credit = useTabForm<CreditForm>(() => props.orderId, 'bank-credits', () => ({
    credit_type: null,
    bank_name: null,
    credit_amount: null,
    duration_days: null,
    due_date: null,
    status: pending(),
    currency_id: props.currencyId,
    notes: null,
    attachment: null,
    remove_attachment: false,
}));

const paymentFile = ref<FileRef | null>(null);
const creditFile = ref<FileRef | null>(null);

const paymentForm = payment.form;
const creditForm = credit.form;
const paymentOpen = payment.open;
const creditOpen = credit.open;

function addPayment(): void {
    paymentFile.value = null;
    payment.add();
}

function editPayment(row: TabRecord): void {
    paymentFile.value = (row.attachment as FileRef | null) ?? null;
    payment.edit(row.id, row.form as Partial<PaymentForm>);
}

function addCredit(): void {
    creditFile.value = null;
    credit.add();
}

function editCredit(row: TabRecord): void {
    creditFile.value = (row.attachment as FileRef | null) ?? null;
    credit.edit(row.id, row.form as Partial<CreditForm>);
}
</script>

<template>
    <div class="grid gap-4">
        <TabTable
            :title="t('Cash Payments')"
            :rows="payments"
            :columns="[
                { key: 'amount', label: t('Amount'), class: 'text-end' },
                { key: 'percentage', label: t('Percentage'), class: 'text-end' },
                { key: 'due_date', label: t('Due Date') },
                { key: 'payment_type', label: t('Payment Type') },
                { key: 'status', label: t('Payment Status') },
                { key: 'currency', label: t('Currency') },
                { key: 'notes', label: t('Notes') },
            ]"
            :add-label="t('Add Payment')"
            can-add
            @add="addPayment"
        >
            <template #cell-amount="{ row }"><span class="font-mono font-semibold tabular-nums" dir="ltr">{{ row.amount }}</span></template>
            <template #cell-percentage="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.percentage ?? '—' }}</span></template>
            <template #cell-due_date="{ row }"><span dir="ltr">{{ row.due_date }}</span></template>
            <template #cell-status="{ row }"><Badge v-if="row.status" :tone="(row.status as StatusBadge).tone" dot>{{ (row.status as StatusBadge).label }}</Badge></template>
            <template #actions="{ row }"><RowActions @edit="editPayment(row)" @delete="payment.remove(row.id, t('Are you sure you want to delete this payment?'))" /></template>
        </TabTable>

        <TabTable
            :title="t('Bank Credits')"
            :rows="bankCredits"
            :columns="[
                { key: 'credit_type', label: t('Credit Type') },
                { key: 'credit_amount', label: t('Credit Amount'), class: 'text-end' },
                { key: 'bank_name', label: t('Bank') },
                { key: 'due_date', label: t('Due Date') },
                { key: 'status', label: t('Credit Status') },
                { key: 'currency', label: t('Currency') },
                { key: 'notes', label: t('Notes') },
            ]"
            :add-label="t('Add Bank Credit')"
            can-add
            @add="addCredit"
        >
            <template #cell-credit_amount="{ row }"><span class="font-mono font-semibold tabular-nums" dir="ltr">{{ row.credit_amount }}</span></template>
            <template #cell-due_date="{ row }"><span dir="ltr">{{ row.due_date }}</span></template>
            <template #cell-status="{ row }"><Badge v-if="row.status" :tone="(row.status as StatusBadge).tone" dot>{{ (row.status as StatusBadge).label }}</Badge></template>
            <template #actions="{ row }"><RowActions @edit="editCredit(row)" @delete="credit.remove(row.id, t('Are you sure you want to delete this bank credit?'))" /></template>
        </TabTable>
    </div>

    <Modal v-model:open="paymentOpen" :title="payment.editingId.value ? t('Edit') : t('Add Payment')" width="max-w-2xl">
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="payment.submit">
            <SelectInput v-model="paymentForm.payment_type" :label="t('Payment Type')" required :options="options.paymentTypeOptions" :placeholder="t('Select')" :error="paymentForm.errors.payment_type" />
            <SelectInput v-model="paymentForm.status" :label="t('Payment Status')" required :options="options.financialStatusOptions" :error="paymentForm.errors.status" />
            <TextInput v-model="paymentForm.amount" type="number" step="0.0001" min="0" dir="ltr" :label="t('Amount')" required :error="paymentForm.errors.amount" />
            <TextInput v-model="paymentForm.percentage" type="number" step="0.01" min="0" max="100" dir="ltr" :label="t('Percentage')" :error="paymentForm.errors.percentage" />
            <TextInput v-model="paymentForm.due_date" type="date" :label="t('Due Date')" required :error="paymentForm.errors.due_date" />
            <SelectInput v-model="paymentForm.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :error="paymentForm.errors.currency_id" />
            <div class="sm:col-span-2">
                <FileField v-model="paymentForm.attachment" v-model:remove="paymentForm.remove_attachment" :label="t('Attachments')" :current="paymentFile" :error="paymentForm.errors.attachment" />
            </div>
            <div class="sm:col-span-2"><TextareaInput v-model="paymentForm.notes" :label="t('Notes')" :rows="2" :error="paymentForm.errors.notes" /></div>
        </form>
        <template #footer>
            <Button @click="paymentOpen = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="paymentForm.processing" @click="payment.submit">{{ t('Save') }}</Button>
        </template>
    </Modal>

    <Modal v-model:open="creditOpen" :title="credit.editingId.value ? t('Edit') : t('Add Bank Credit')" width="max-w-2xl">
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="credit.submit">
            <SelectInput v-model="creditForm.credit_type" :label="t('Credit Type')" required :options="options.bankCreditTypeOptions" :placeholder="t('Select')" :error="creditForm.errors.credit_type" />
            <TextInput v-model="creditForm.bank_name" :label="t('Bank')" required :maxlength="255" :error="creditForm.errors.bank_name" />
            <TextInput v-model="creditForm.credit_amount" type="number" step="0.0001" min="0" dir="ltr" :label="t('Credit Amount')" required :error="creditForm.errors.credit_amount" />
            <TextInput v-model="creditForm.duration_days" type="number" step="1" min="0" dir="ltr" :label="t('Duration (Days)')" :error="creditForm.errors.duration_days" />
            <TextInput v-model="creditForm.due_date" type="date" :label="t('Due Date')" required :error="creditForm.errors.due_date" />
            <SelectInput v-model="creditForm.status" :label="t('Credit Status')" required :options="options.financialStatusOptions" :error="creditForm.errors.status" />
            <SelectInput v-model="creditForm.currency_id" :label="t('Currency')" required :options="options.currencyOptions" :error="creditForm.errors.currency_id" />
            <div class="sm:col-span-2">
                <FileField v-model="creditForm.attachment" v-model:remove="creditForm.remove_attachment" :label="t('Attachments')" :current="creditFile" :error="creditForm.errors.attachment" />
            </div>
            <div class="sm:col-span-2"><TextareaInput v-model="creditForm.notes" :label="t('Notes')" :rows="2" :error="creditForm.errors.notes" /></div>
        </form>
        <template #footer>
            <Button @click="creditOpen = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="creditForm.processing" @click="credit.submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
