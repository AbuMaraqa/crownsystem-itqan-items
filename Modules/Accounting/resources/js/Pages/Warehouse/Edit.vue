<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import WarehouseFields, { type WarehouseForm, type WarehouseFormOptions } from './WarehouseFields.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<WarehouseFormOptions & { warehouse: WarehouseForm & { id: number } }>();

const { id: warehouseId, ...values } = props.warehouse;
const form = useForm<WarehouseForm>(values);
</script>

<template>
    <PageHeader :title="t('Edit Warehouse')" :parents="[{ title: t('Warehouses List'), href: route('accounting.warehouses.index') }]" />
    <form @submit.prevent="form.put(route('accounting.warehouses.update', warehouseId))">
        <WarehouseFields :form="form" :options="{ branchOptions: props.branchOptions, branchForm: props.branchForm }" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.warehouses.index')" />
    </form>
</template>
