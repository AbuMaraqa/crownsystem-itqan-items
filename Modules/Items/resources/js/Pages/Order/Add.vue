<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import OrderFields from './OrderFields.vue';
import type { OrderForm, OrderFormOptions } from './parts/types';

defineOptions({ layout: AdminLayout });

const props = defineProps<OrderFormOptions>();

const PENDING = 1;
const CASH = 1;
const UNPAID = 2;

const form = useForm<OrderForm>({
    user_id: null,
    branch_id: null,
    city_id: null,
    district_id: null,
    items: [{ product_id: null, quantity: 1, price: 0 }],
    delivery_fee: 0,
    status: PENDING,
    payment_method: CASH,
    payment_status: UNPAID,
    delivery_address: '',
    contact_phone: '',
    notes: '',
});

function submit(): void {
    if (!form.processing) form.post(route('orders.store'));
}
</script>

<template>
    <PageHeader :title="t('Add Order')" :parents="[{ title: t('Orders List'), href: route('orders.index') }]" />
    <form @submit.prevent="submit">
        <OrderFields :form="form" :options="props" lookup-route="orders.lookup" />
        <FormActions :processing="form.processing" :cancel-href="route('orders.index')" :submit-label="t('Save')" />
    </form>
</template>
