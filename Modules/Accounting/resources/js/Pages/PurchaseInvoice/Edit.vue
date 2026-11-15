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
import { CircleAlert, CircleCheck, Printer, Trash2, X } from '@/lib/icons';
import { route } from '@/lib/route';

import PaymentAllocationPanel, { type AllocationPanelData } from '../parts/PaymentAllocationPanel.vue';
import PurchaseInvoiceFields from './PurchaseInvoiceFields.vue';
import type { PurchaseDocumentOptions, PurchaseInvoiceForm, PurchaseInvoiceRecord } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<PurchaseDocumentOptions & { invoice: PurchaseInvoiceForm; record: PurchaseInvoiceRecord; allocationPanel: AllocationPanelData | null }>();

const form = useForm<PurchaseInvoiceForm>(props.invoice);
const reason = ref('');

// After a save the server sends the lines again with their ids: the form starts from them, or the
// next save would delete and recreate the new lines.
watch(
    () => props.invoice,
    (invoice) => {
        form.defaults(invoice);
        form.reset();
    },
);

const id = computed(() => props.record.id);
const editable = computed(() => props.record.editable);

async function post(): Promise<void> {
    if (await confirm({ title: t('Post this purchase invoice? Its journal entry will be posted and the goods received.'), confirmLabel: t('Post Invoice'), tone: 'primary' })) {
        // Saves the draft first, as the old page did.
        if (editable.value) form.post(route('accounting.purchase-invoices.post', id.value), { preserveScroll: true });
        else router.post(route('accounting.purchase-invoices.post', id.value), {}, { preserveScroll: true });
    }
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t('Cancel this purchase invoice? A reversal journal entry will be posted.'), confirmLabel: t('Cancel Invoice') })) {
        router.post(route('accounting.purchase-invoices.cancel', id.value), { reason: reason.value || null }, { preserveScroll: true, onSuccess: () => (reason.value = '') });
    }
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: props.record.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.purchase-invoices.destroy', id.value));
    }
}
</script>

<template>
    <PageHeader :title="`${t('Purchase Invoice')} ${record.reference_no}`" :parents="[{ title: t('Purchase Invoices'), href: route('accounting.purchase-invoices.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="editable && record.can_post && can('Purchase Invoice Post')" variant="primary" :loading="form.processing" @click="post"><CircleCheck />{{ t('Post Invoice') }}</Button>
            <template v-if="record.can_cancel && can('Purchase Invoice Cancel')">
                <TextInput v-model="reason" class="w-56" :placeholder="t('Cancellation reason (optional)')" :aria-label="t('Cancellation reason (optional)')" />
                <Button variant="danger" @click="cancel"><X />{{ t('Cancel Invoice') }}</Button>
            </template>
            <Button v-if="can('Purchase Invoice View')" external new-tab :href="route('accounting.purchase-invoices.pdf', id)"><Printer />{{ t('Print') }}</Button>
            <Button v-if="record.can_delete && can('Purchase Invoice Delete')" variant="ghost" :aria-label="t('Delete')" @click="remove"><Trash2 /></Button>
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3">
        <div v-if="record.purchase_order" class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-blue-light-200 bg-blue-light-50 px-4 py-3 text-sm text-blue-light-800 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-300">
            <CircleAlert class="size-5 shrink-0" />
            <span>{{ t('This purchase invoice was created from purchase order') }}</span>
            <Link :href="route('accounting.purchase-orders.edit', record.purchase_order.id)" class="font-semibold underline"><bdi>{{ record.purchase_order.reference_no }}</bdi></Link>
            <template v-if="record.purchase_quotation">
                <span>· {{ t('Prices from purchase quotation') }}</span>
                <Link :href="route('accounting.purchase-quotations.edit', record.purchase_quotation.id)" class="font-semibold underline"><bdi>{{ record.purchase_quotation.reference_no }}</bdi></Link>
            </template>
            <span v-if="editable">· {{ t('Items and sub-items follow the order; enter the prices and discounts.') }}</span>
        </div>

        <div v-if="record.cancelled" class="rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-sm font-medium text-error-700 dark:border-error-500/25 dark:bg-error-500/10 dark:text-error-400">
            {{ t('This document was cancelled on :date.', { date: record.cancelled_at ?? '' }) }}
            <template v-if="record.cancellation_reason"> — {{ record.cancellation_reason }}</template>
        </div>

        <p v-if="record.journal_entry" class="text-sm text-muted">
            {{ t('Journal Entry') }}:
            <Link v-if="can('Journal Entry View')" :href="route('accounting.journal-entries.show', record.journal_entry.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400"><bdi>{{ record.journal_entry.entry_number }}</bdi></Link>
            <bdi v-else>{{ record.journal_entry.entry_number }}</bdi>
        </p>
    </div>

    <form @submit.prevent="form.put(route('accounting.purchase-invoices.update', id), { preserveScroll: true })">
        <PurchaseInvoiceFields :form="form" :options="props" :reference-no="record.reference_no" :status-label="record.status?.label ?? ''" :client="record.client" :record="record" :readonly="!editable" />

        <PaymentAllocationPanel v-if="allocationPanel" class="mt-4" :panel="allocationPanel" />

        <FormActions v-if="editable" :processing="form.processing" :cancel-href="route('accounting.purchase-invoices.index')" :submit-label="t('Save Draft')" />
    </form>
</template>
