<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import SalesInvoiceFields from './SalesInvoiceFields.vue';
import type { SalesInvoiceForm, SalesInvoiceOptions } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<SalesInvoiceOptions & { invoice: SalesInvoiceForm; referenceNo: string; statusLabel: string }>();

const form = useForm<SalesInvoiceForm>(props.invoice);
</script>

<template>
    <PageHeader :title="t('Add Sales Invoice')" :parents="[{ title: t('Sales Invoices'), href: route('accounting.sales-invoices.index') }]" />

    <form @submit.prevent="form.post(route('accounting.sales-invoices.store'))">
        <SalesInvoiceFields :form="form" :options="props" :reference-no="referenceNo" :status-label="statusLabel" chooses-order />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.sales-invoices.index')" :submit-label="t('Create Sales Invoice')" />
    </form>
</template>
