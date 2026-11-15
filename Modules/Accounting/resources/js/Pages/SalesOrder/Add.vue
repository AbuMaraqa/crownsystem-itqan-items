<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { Option } from '../parts/types';
import SalesOrderFields from './SalesOrderFields.vue';
import type { SalesOrderForm, SalesOrderOptions } from './types';

defineOptions({ layout: AdminLayout });

const props = defineProps<
    SalesOrderOptions & { order: SalesOrderForm; referenceNo: string; statusLabel: string; client: Option | null; quotation: Option | null }
>();

const form = useForm<SalesOrderForm>(props.order);
</script>

<template>
    <PageHeader :title="t('Add Sales Order')" :parents="[{ title: t('Sales Orders'), href: route('accounting.sales-orders.index') }]" />

    <form @submit.prevent="form.post(route('accounting.sales-orders.store'))">
        <SalesOrderFields :form="form" :options="props" :reference-no="referenceNo" :status-label="statusLabel" :client="client" :quotation="quotation" chooses-quotation />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.sales-orders.index')" :submit-label="t('Save')" />
    </form>
</template>
