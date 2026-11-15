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
import { CircleCheck, ExternalLink, Printer, ShoppingCart, Trash2, X } from '@/lib/icons';
import { route } from '@/lib/route';

import SalesQuotationFields from './SalesQuotationFields.vue';
import type { SalesQuotationForm, SalesQuotationOptions, SalesQuotationRecord } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<SalesQuotationOptions & { quotation: SalesQuotationForm; record: SalesQuotationRecord }>();

const form = useForm<SalesQuotationForm>(props.quotation);

// After a save the server sends the lines again with their ids: the form starts from them, or the
// next save would delete and recreate the new lines.
watch(
    () => props.quotation,
    (quotation) => {
        form.defaults(quotation);
        form.reset();
    },
);

const id = computed(() => props.record.id);
const editable = computed(() => props.record.editable);

async function approve(): Promise<void> {
    if (await confirm({ title: t('Approve this sales quotation?'), confirmLabel: t('Approve'), tone: 'primary' })) {
        form.post(route('accounting.sales-quotations.approve', id.value), { preserveScroll: true });
    }
}

async function convert(): Promise<void> {
    if (await confirm({ title: t('Create a sales order from this quotation?'), confirmLabel: t('Create Sales Order'), tone: 'primary' })) {
        // Saves the open changes first, as the old page did.
        if (editable.value && can('Sales Quotation Update')) form.post(route('accounting.sales-quotations.convert', id.value));
        else router.post(route('accounting.sales-quotations.convert', id.value));
    }
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t('Cancel this sales quotation?'), confirmLabel: t('Cancel Quotation') })) {
        router.post(route('accounting.sales-quotations.cancel', id.value), {}, { preserveScroll: true });
    }
}

async function remove(): Promise<void> {
    if (await confirm({ title: t('Delete :name?', { name: props.record.reference_no }), description: t('This cannot be undone.') })) {
        router.delete(route('accounting.sales-quotations.destroy', id.value));
    }
}
</script>

<template>
    <PageHeader :title="`${t('Sales Quotation')} ${record.reference_no}`" :parents="[{ title: t('Sales Quotations'), href: route('accounting.sales-quotations.index') }]">
        <template #actions>
            <Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge>
            <Button v-if="editable && can('Sales Quotation Approve')" variant="primary" :loading="form.processing" @click="approve"><CircleCheck />{{ t('Approve') }}</Button>
            <Button v-if="record.can_convert && can('Sales Order Create')" variant="light" @click="convert"><ShoppingCart />{{ t('Create Sales Order') }}</Button>
            <Button v-else-if="record.sales_order" :href="route('accounting.sales-orders.edit', record.sales_order.id)" external><ExternalLink />{{ t('Open Sales Order') }}</Button>
            <Button v-if="record.can_convert && can('Sales Quotation Cancel')" variant="danger" @click="cancel"><X />{{ t('Cancel') }}</Button>
            <Button v-if="can('Sales Quotation View')" external new-tab :href="route('accounting.sales-quotations.pdf', id)"><Printer />{{ t('Print') }}</Button>
            <Button v-if="record.can_delete && can('Sales Quotation Delete')" variant="ghost" :aria-label="t('Delete')" @click="remove"><Trash2 /></Button>
        </template>
    </PageHeader>

    <form @submit.prevent="form.put(route('accounting.sales-quotations.update', id), { preserveScroll: true })">
        <SalesQuotationFields
            :form="form"
            :options="props"
            :reference-no="record.reference_no"
            :status-label="record.status?.label ?? ''"
            :client="record.client"
            :margin-hint="record.margin_hint"
            :readonly="!editable"
        />
        <FormActions v-if="editable && can('Sales Quotation Update')" :processing="form.processing" :cancel-href="route('accounting.sales-quotations.index')" :submit-label="t('Update')" />
    </form>
</template>
