<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { CircleCheck, Printer, Trash2, X } from '@/lib/icons';
import { route } from '@/lib/route';

import PaymentAllocationPanel from '../parts/PaymentAllocationPanel.vue';
import SalesInvoiceFields from './SalesInvoiceFields.vue';
import type { AllocationPanelData, SalesInvoiceForm, SalesInvoiceOptions, SalesInvoiceRecord } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<SalesInvoiceOptions & { invoice: SalesInvoiceForm; record: SalesInvoiceRecord; allocationPanel: AllocationPanelData | null }>();

const form = useForm<SalesInvoiceForm>(props.invoice);
const reason = ref('');

// After a save the server sends the lines again with their ids: the form starts from them.
watch(
    () => props.invoice,
    (invoice) => {
        form.defaults(invoice);
        form.reset();
    },
);

const id = computed(() => props.record.id);
const editable = computed(() => props.record.editable);
const orderOption = computed(() => (props.record.sales_order ? { value: props.record.sales_order.id, label: props.record.sales_order.reference_no } : null));

/** Posting saves the open changes first (with the update permission), as the old page did. */
async function post(): Promise<void> {
    if (!(await confirm({ title: t('Post this sales invoice? Its journal entry will be posted and the goods issued from stock.'), confirmLabel: t('Post Invoice'), tone: 'primary' }))) return;

    const url = route('accounting.sales-invoices.post', id.value);

    if (editable.value) form.post(url, { preserveScroll: true });
    else router.post(url, {}, { preserveScroll: true });
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t('Cancel this sales invoice? A reversal journal entry will be posted and its goods returned to stock.'), confirmLabel: t('Cancel Invoice') })) {
        router.post(route('accounting.sales-invoices.cancel', id.value), { reason: reason.value || null }, { preserveScroll: true, onSuccess: () => (reason.value = '') });
    }
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: props.record.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.sales-invoices.destroy', id.value));
    }
}
</script>

<template>
    <PageHeader :title="`${t('Sales Invoice')} ${record.reference_no}`" :parents="[{ title: t('Sales Invoices'), href: route('accounting.sales-invoices.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="record.can_post && can('Sales Invoice Post')" variant="primary" :loading="form.processing" @click="post"><CircleCheck />{{ t('Post Invoice') }}</Button>
            <template v-if="record.can_cancel && can('Sales Invoice Cancel')">
                <TextInput v-model="reason" class="w-56" :placeholder="t('Cancellation reason (optional)')" />
                <Button variant="danger" @click="cancel"><X />{{ t('Cancel Invoice') }}</Button>
            </template>
            <Button v-if="can('Sales Invoice View')" external new-tab :href="route('accounting.sales-invoices.pdf', id)"><Printer />{{ t('Print') }}</Button>
            <Button v-if="record.can_delete && can('Sales Invoice Delete')" variant="ghost" :aria-label="t('Delete')" @click="remove"><Trash2 /></Button>
        </template>
    </PageHeader>

    <div class="mb-4 space-y-3 empty:hidden">
        <div v-if="record.cancelled" class="rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-sm font-medium text-error-700 dark:border-error-500/25 dark:bg-error-500/10 dark:text-error-400">
            {{ t('This document was cancelled on :date.', { date: record.cancelled_at ?? '' }) }}
            <template v-if="record.cancellation_reason"> — {{ record.cancellation_reason }}</template>
        </div>

        <div v-if="record.sales_order" class="rounded-xl border border-blue-light-200 bg-blue-light-50 px-4 py-3 text-sm font-medium text-blue-light-800 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-300">
            {{ t('This sales invoice was created from sales order') }}
            <Link v-if="can('Sales Order Update')" class="font-semibold underline underline-offset-2" :href="route('accounting.sales-orders.edit', record.sales_order.id)" dir="ltr">{{ record.sales_order.reference_no }}</Link>
            <span v-else class="font-semibold" dir="ltr">{{ record.sales_order.reference_no }}</span>
        </div>
    </div>

    <form @submit.prevent="form.put(route('accounting.sales-invoices.update', id), { preserveScroll: true })">
        <SalesInvoiceFields
            :form="form"
            :options="props"
            :reference-no="record.reference_no"
            :status-label="record.status?.label ?? ''"
            :client="record.client"
            :sales-order="orderOption"
            :margin-hint="record.margin_hint"
            :readonly="!editable"
            :from-order="record.from_order"
            :materials-weight="record.materials_weight"
        />
        <FormActions v-if="editable" :processing="form.processing" :cancel-href="route('accounting.sales-invoices.index')" :submit-label="t('Save Draft')" />
    </form>

    <PaymentAllocationPanel v-if="allocationPanel" class="mt-4" :panel="allocationPanel" />
</template>
