<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import SalesQuotationFields from './SalesQuotationFields.vue';
import type { SalesQuotationForm, SalesQuotationOptions } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<SalesQuotationOptions & { quotation: SalesQuotationForm; referenceNo: string; statusLabel: string }>();

const form = useForm<SalesQuotationForm>(props.quotation);
</script>

<template>
    <PageHeader :title="t('Add Sales Quotation')" :parents="[{ title: t('Sales Quotations'), href: route('accounting.sales-quotations.index') }]" />

    <form @submit.prevent="form.post(route('accounting.sales-quotations.store'))">
        <SalesQuotationFields :form="form" :options="props" :reference-no="referenceNo" :status-label="statusLabel" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.sales-quotations.index')" :submit-label="t('Save')" />
    </form>
</template>
