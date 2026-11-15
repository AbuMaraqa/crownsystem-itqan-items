<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { emptyProduct, toRequestBody } from './parts/productForm';
import type { ProductContext, ProductForm, ProductFormOptions } from './parts/types';
import ProductFields from './ProductFields.vue';

defineOptions({ layout: AdminLayout });

defineProps<{ options: ProductFormOptions }>();

const form = useForm<ProductForm>(emptyProduct());

const context: ProductContext = { productId: null, lookupRoute: 'products.lookup', labels: {}, subProducts: [], madeFrom: [], stock: null, stockLinks: [] };

function submit(): void {
    if (form.processing) return;

    form.transform((data) => toRequestBody(data as ProductForm) as never).post(route('products.store'), { forceFormData: true });
}
</script>

<template>
    <PageHeader :title="t('Add Product')" :parents="[{ title: t('Products List'), href: route('products.index') }]" />
    <form @submit.prevent="submit">
        <ProductFields :form="form" :options="options" :context="context" />
        <FormActions :processing="form.processing" :cancel-href="route('products.index')" />
    </form>
</template>
