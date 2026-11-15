<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import type { ExistingImage } from '@/Components/form/ImageUpload.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import OfferFields, { type OfferForm } from './OfferFields.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    offer: Omit<OfferForm, 'image' | 'removed_image'> & { id: number };
    target: Option | null;
    images: ExistingImage[];
    types: Option[];
    branches: Option[];
}>();

const { id: offerId, ...values } = props.offer;
const form = useForm<OfferForm>({ ...values, image: null, removed_image: [] });

function submit(): void {
    if (form.processing) return;

    form.transform((data) => ({ ...data, _method: 'put' })).post(route('offers.update', offerId), { forceFormData: true });
}
</script>

<template>
    <PageHeader :title="t('Edit Offer')" :parents="[{ title: t('Offers List'), href: route('offers.index') }]" />
    <form @submit.prevent="submit">
        <OfferFields :form="form" :types="types" :branches="branches" targets-route="offers.edit-targets" :target="target" :images="images" />
        <FormActions :processing="form.processing" :cancel-href="route('offers.index')" />
    </form>
</template>
