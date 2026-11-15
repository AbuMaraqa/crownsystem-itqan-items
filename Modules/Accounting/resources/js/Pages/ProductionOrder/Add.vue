<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import ProductionOrderFields from './ProductionOrderFields.vue';
import type { ProductionContext, ProductionOptions, ProductionOrderForm } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<ProductionOptions & { order: ProductionOrderForm; context: ProductionContext; referenceNo: string; statusLabel: string }>();

const form = useForm<ProductionOrderForm>(props.order);
</script>

<template>
    <PageHeader :title="t('Add Production Order')" :parents="[{ title: t('Production Orders'), href: route('accounting.production-orders.index') }]" />

    <form @submit.prevent="form.post(route('accounting.production-orders.store'))">
        <ProductionOrderFields :form="form" :options="props" :context="context" :reference-no="referenceNo" :status-label="statusLabel" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.production-orders.index')" :submit-label="t('Save')" />
    </form>
</template>
