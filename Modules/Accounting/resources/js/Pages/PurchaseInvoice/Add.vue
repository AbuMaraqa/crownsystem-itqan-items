<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import PurchaseInvoiceFields from './PurchaseInvoiceFields.vue';
import type { PurchaseDocumentOptions, PurchaseInvoiceForm } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<PurchaseDocumentOptions & { invoice: PurchaseInvoiceForm; referenceNo: string; statusLabel: string }>();

const form = useForm<PurchaseInvoiceForm>(props.invoice);
</script>

<template>
    <PageHeader :title="t('Add Purchase Invoice')" :parents="[{ title: t('Purchase Invoices'), href: route('accounting.purchase-invoices.index') }]" />

    <form @submit.prevent="form.post(route('accounting.purchase-invoices.store'))">
        <PurchaseInvoiceFields :form="form" :options="props" :reference-no="referenceNo" :status-label="statusLabel" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.purchase-invoices.index')" :submit-label="t('Save')" />
    </form>
</template>
