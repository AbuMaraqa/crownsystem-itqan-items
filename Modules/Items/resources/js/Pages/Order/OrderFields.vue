<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import Repeater from '@/Components/form/Repeater.vue';
import SelectInput, { type Option } from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { OrderForm, OrderFormOptions, OrderLine } from './parts/types';

/**
 * The order form (add and edit): the order details with its products and totals, and the order
 * information beside them. A product's price is its base price and cannot be typed; the totals here
 * are a preview, the server computes the saved ones.
 */
const props = defineProps<{
    form: InertiaForm<OrderForm>;
    options: OrderFormOptions;
    /** `orders.lookup` on Add, `orders.edit-lookup` on Edit: each searches with its page's permission. */
    lookupRoute: string;
}>();

const lookup = (type: string): string => route(props.lookupRoute, { type });

const subTotal = computed(() => props.form.items.reduce((sum, line) => sum + Number(line.price || 0) * Number(line.quantity || 0), 0));
const grandTotal = computed(() => subTotal.value + Number(props.form.delivery_fee || 0));
const money = (value: number): string => value.toFixed(2);

const newLine = (): OrderLine => ({ product_id: null, quantity: 1, price: 0 });

function picked(line: OrderLine, option: Option & { price?: number }): void {
    line.price = Number(option.price ?? 0);
}
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-3">
        <section class="space-y-5 rounded-xl border border-line bg-card p-5 shadow-card lg:col-span-2">
            <h2 class="text-base font-semibold">{{ t('Order Details') }}</h2>

            <div class="grid gap-4 md:grid-cols-2">
                <RemoteSelect v-model="form.user_id" :url="lookup('customers')" :initial="options.labels.customers" :label="t('Customer')" :error="form.errors.user_id" required />
                <SelectInput v-model="form.branch_id" :options="options.branches" :label="t('Branch')" :placeholder="t('Select')" :error="form.errors.branch_id" required />
            </div>

            <Repeater v-model="form.items" name="items" :errors="form.errors" :label="t('Products')" :add-label="t('Add Product')" :reorderable="false" :new-item="newLine">
                <template #item="{ item, error }">
                    <div class="grid gap-3 md:grid-cols-3">
                        <RemoteSelect v-model="item.product_id" :url="lookup('products')" :initial="options.labels.products" :label="t('Product')" :error="error('product_id')" required @picked="(option) => picked(item, option)" />
                        <TextInput v-model="item.quantity" type="number" min="1" step="1" :label="t('Quantity')" :error="error('quantity')" required />
                        <TextInput :model-value="money(Number(item.price || 0))" :label="t('Price per unit')" disabled dir="ltr" />
                    </div>
                </template>
            </Repeater>

            <div class="grid gap-4 rounded-lg bg-surface p-4 md:grid-cols-3">
                <TextInput :model-value="money(subTotal)" :label="t('Subtotal')" disabled dir="ltr" />
                <TextInput v-model="form.delivery_fee" type="number" min="0" step="any" :label="t('Delivery Fee')" :error="form.errors.delivery_fee" />
                <TextInput :model-value="money(grandTotal)" :label="t('Grand Total')" disabled dir="ltr" />
            </div>
        </section>

        <section class="space-y-4 rounded-xl border border-line bg-card p-5 shadow-card">
            <h2 class="text-base font-semibold">{{ t('Order Information') }}</h2>
            <SelectInput v-model="form.status" :options="options.statuses" :label="t('Order Status')" :error="form.errors.status" required />
            <SelectInput v-model="form.payment_method" :options="options.paymentMethods" :label="t('Payment Method')" :error="form.errors.payment_method" required />
            <SelectInput v-model="form.payment_status" :options="options.paymentStatuses" :label="t('Payment Status')" :error="form.errors.payment_status" required />
            <RemoteSelect v-model="form.city_id" :url="lookup('cities')" :initial="options.labels.cities" :label="t('City')" :error="form.errors.city_id" required />
            <RemoteSelect v-model="form.district_id" :url="lookup('districts')" :initial="options.labels.districts" :label="t('District')" :error="form.errors.district_id" required />
            <TextareaInput v-model="form.delivery_address" :rows="3" :label="t('Delivery Address')" :error="form.errors.delivery_address" required />
            <TextInput v-model="form.contact_phone" :label="t('Contact Phone')" :error="form.errors.contact_phone" required dir="ltr" />
            <TextareaInput v-model="form.notes" :rows="3" :label="t('Notes')" :error="form.errors.notes" />
        </section>
    </div>
</template>
