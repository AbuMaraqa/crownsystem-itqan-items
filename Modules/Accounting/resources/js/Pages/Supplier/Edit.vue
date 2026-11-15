<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import SupplierFields, { type SupplierForm } from './SupplierFields.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{ supplier: SupplierForm & { id: number } }>();

const { id: supplierId, ...values } = props.supplier;
const form = useForm<SupplierForm>(values);
</script>

<template>
    <PageHeader :title="t('Edit Supplier')" :parents="[{ title: t('Suppliers'), href: route('accounting.suppliers.index') }]" />
    <form @submit.prevent="form.put(route('accounting.suppliers.update', supplierId))">
        <SupplierFields :form="form" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.suppliers.index')" />
    </form>
</template>
