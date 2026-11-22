<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { toRequestBody, withUploadState } from './parts/productForm';
import type { MadeFromRow, ProductContext, ProductForm, ProductFormOptions, StockSummary, VariantStock, VariationRow } from './parts/types';
import ProductFields from './ProductFields.vue';

defineOptions({ layout: AdminLayout });

type Saved<T> = Omit<T, 'image' | 'gallery' | 'removed_image' | 'removed_gallery'>;

const props = defineProps<{
    options: ProductFormOptions;
    product: Omit<Saved<ProductForm>, 'variations' | 'attributes'> & {
        id: number;
        variations: Saved<VariationRow>[];
        /** An empty PHP array arrives as a list. */
        attributes: Record<string, number[]> | never[];
    };
    labels: Record<string, string> | never[];
    madeFrom: MadeFromRow[];
    stock: StockSummary | null;
    stockLinks: { label: string; href: string }[];
    /** An empty PHP array arrives as a list. */
    variantStock: Record<string, VariantStock> | never[];
}>();

const { id: productId, variations, attributes, ...values } = props.product;

const form = useForm<ProductForm>({
    ...withUploadState(values),
    variations: variations.map((row) => withUploadState(row)),
    attributes: Array.isArray(attributes) ? {} : attributes,
});

const context: ProductContext = {
    productId,
    lookupRoute: 'products.edit-lookup',
    labels: Array.isArray(props.labels) ? {} : props.labels,
    madeFrom: props.madeFrom,
    stock: props.stock,
    stockLinks: props.stockLinks,
    variantStock: Array.isArray(props.variantStock) ? {} : props.variantStock,
};

function submit(): void {
    if (form.processing) return;

    form.transform((data) => toRequestBody(data as ProductForm, { _method: 'put' }) as never).post(route('products.update', productId), { forceFormData: true });
}
</script>

<template>
    <PageHeader :title="t('Edit Product')" :parents="[{ title: t('Products List'), href: route('products.index') }]" />
    <form @submit.prevent="submit">
        <ProductFields :form="form" :options="options" :context="context" collapsed />
        <FormActions :processing="form.processing" :cancel-href="route('products.index')" />
    </form>
</template>
