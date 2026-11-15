<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import WarehouseFields, { type WarehouseForm, type WarehouseFormOptions } from './WarehouseFields.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<WarehouseFormOptions>();

const form = useForm<WarehouseForm>({
    name: '',
    code: null,
    branch_id: null,
    phone: null,
    email: null,
    latitude: null,
    longitude: null,
    is_active: true,
    address: null,
    description: null,
});
</script>

<template>
    <PageHeader :title="t('Add Warehouse')" :parents="[{ title: t('Warehouses List'), href: route('accounting.warehouses.index') }]" />
    <form @submit.prevent="form.post(route('accounting.warehouses.store'))">
        <WarehouseFields :form="form" :options="props" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.warehouses.index')" :submit-label="t('Add Warehouse')" />
    </form>
</template>
