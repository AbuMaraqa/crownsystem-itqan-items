<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { CircleCheck, DollarSign, ExternalLink, Printer, Trash2, X } from '@/lib/icons';
import { route } from '@/lib/route';

import PurchaseQuotationFields from './PurchaseQuotationFields.vue';
import type { PurchaseDocumentOptions, PurchaseQuotationForm, PurchaseQuotationRecord, QuotedOrder } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<PurchaseDocumentOptions & { quotation: PurchaseQuotationForm; record: PurchaseQuotationRecord; order: QuotedOrder | null }>();

const form = useForm<PurchaseQuotationForm>(props.quotation);

// After a save the server sends the lines again: the form starts from them.
watch(
    () => props.quotation,
    (quotation) => {
        form.defaults(quotation);
        form.reset();
    },
);

const id = computed(() => props.record.id);
const editable = computed(() => props.record.editable && can('Purchase Quotation Update'));

async function approve(): Promise<void> {
    if (await confirm({ title: t('Approve this quotation? Its prices become fixed and the purchase invoice will be created from them.'), confirmLabel: t('Approve'), tone: 'primary' })) {
        // Saves the draft first, as the old page did.
        if (editable.value) form.post(route('accounting.purchase-quotations.approve', id.value), { preserveScroll: true });
        else router.post(route('accounting.purchase-quotations.approve', id.value), {}, { preserveScroll: true });
    }
}

function createInvoice(): void {
    router.post(route('accounting.purchase-quotations.invoice', id.value));
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t('Cancel this quotation? The purchase order can then get a new quotation.'), confirmLabel: t('Cancel') })) {
        router.post(route('accounting.purchase-quotations.cancel', id.value), {}, { preserveScroll: true });
    }
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Delete this draft?'), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.purchase-quotations.destroy', id.value));
    }
}
</script>

<template>
    <PageHeader :title="`${t('Purchase Quotation')} ${record.reference_no}`" :parents="[{ title: t('Purchase Quotations'), href: route('accounting.purchase-quotations.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="record.can_approve && can('Purchase Quotation Approve')" variant="primary" :loading="form.processing" @click="approve"><CircleCheck />{{ t('Approve') }}</Button>
            <Button v-if="record.can_invoice && can('Purchase Invoice Create')" variant="primary" @click="createInvoice"><DollarSign />{{ t('Create Purchase Invoice') }}</Button>
            <Button v-if="record.invoice && can('Purchase Invoice View List')" :href="route('accounting.purchase-invoices.edit', record.invoice.id)"><ExternalLink /><bdi>{{ record.invoice.reference_no }}</bdi></Button>
            <Button v-if="record.can_cancel && can('Purchase Quotation Cancel')" variant="danger" @click="cancel"><X />{{ t('Cancel') }}</Button>
            <Button v-if="can('Purchase Quotation View List')" external new-tab :href="route('accounting.purchase-quotations.pdf', id)"><Printer />{{ t('Print') }}</Button>
            <Button v-if="record.can_delete && can('Purchase Quotation Delete')" variant="ghost" :aria-label="t('Delete')" @click="remove"><Trash2 /></Button>
        </template>
    </PageHeader>

    <p v-if="record.approved_by" class="mb-4 text-sm text-muted">{{ t('Approved by') }} {{ record.approved_by }} · <span dir="ltr">{{ record.approved_at }}</span></p>

    <form @submit.prevent="form.put(route('accounting.purchase-quotations.update', id), { preserveScroll: true })">
        <PurchaseQuotationFields :form="form" :options="props" :order="order" :reference-no="record.reference_no" :status-label="record.status?.label ?? ''" :readonly="!editable" />
        <FormActions v-if="editable" :processing="form.processing" :cancel-href="route('accounting.purchase-quotations.index')" :submit-label="t('Save Draft')" />
    </form>
</template>
