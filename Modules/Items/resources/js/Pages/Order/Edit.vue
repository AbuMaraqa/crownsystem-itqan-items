<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import OrderFields from './OrderFields.vue';
import type { OrderForm, OrderFormOptions, OrderLine } from './parts/types';

defineOptions({ layout: AdminLayout });

const props = defineProps<
    OrderFormOptions & {
        order: Omit<OrderForm, 'items' | 'delivery_address' | 'contact_phone' | 'notes'> & {
            id: number;
            order_number: string;
            items: OrderLine[];
            delivery_address: string | null;
            contact_phone: string | null;
            notes: string | null;
        };
    }
>();

const form = useForm<OrderForm>({
    user_id: props.order.user_id,
    branch_id: props.order.branch_id,
    city_id: props.order.city_id,
    district_id: props.order.district_id,
    items: props.order.items.map((line) => ({ ...line })),
    delivery_fee: props.order.delivery_fee,
    status: props.order.status,
    payment_method: props.order.payment_method,
    payment_status: props.order.payment_status,
    delivery_address: props.order.delivery_address ?? '',
    contact_phone: props.order.contact_phone ?? '',
    notes: props.order.notes ?? '',
});

function submit(): void {
    if (!form.processing) form.put(route('orders.update', props.order.id));
}
</script>

<template>
    <PageHeader :title="`${t('Edit Order')} ${order.order_number}`" :parents="[{ title: t('Orders List'), href: route('orders.index') }]" />
    <form @submit.prevent="submit">
        <OrderFields :form="form" :options="props" lookup-route="orders.edit-lookup" />
        <FormActions :processing="form.processing" :cancel-href="route('orders.index')" :submit-label="t('Update')" />
    </form>
</template>
