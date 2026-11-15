<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import SupplierFields, { type SupplierForm } from './SupplierFields.vue';

defineOptions({ layout: AdminLayout });

const form = useForm<SupplierForm>({ name: '', email: null, phone: null, tax_number: null, address: null });
</script>

<template>
    <PageHeader :title="t('Add Supplier')" :parents="[{ title: t('Suppliers'), href: route('accounting.suppliers.index') }]" />
    <form @submit.prevent="form.post(route('accounting.suppliers.store'))">
        <SupplierFields :form="form" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.suppliers.index')" :submit-label="t('Add Supplier')" />
    </form>
</template>
