<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import PurchaseQuotationFields from './PurchaseQuotationFields.vue';
import type { PurchaseDocumentOptions, PurchaseQuotationForm, QuotedOrder } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<PurchaseDocumentOptions & { quotation: PurchaseQuotationForm; order: QuotedOrder | null; referenceNo: string; statusLabel: string }>();

const form = useForm<PurchaseQuotationForm>(props.quotation);
</script>

<template>
    <PageHeader :title="t('New Purchase Quotation')" :parents="[{ title: t('Purchase Quotations'), href: route('accounting.purchase-quotations.index') }]" />

    <form @submit.prevent="form.post(route('accounting.purchase-quotations.store'))">
        <PurchaseQuotationFields :form="form" :options="props" :order="order" :reference-no="referenceNo" :status-label="statusLabel" />
        <FormActions v-if="order" :processing="form.processing" :cancel-href="route('accounting.purchase-quotations.index')" :submit-label="t('Save Draft')" />
    </form>
</template>
