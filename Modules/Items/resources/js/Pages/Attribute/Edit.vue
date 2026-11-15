<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import AttributeFields, { type AttributeForm } from './AttributeFields.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{ attribute: AttributeForm & { id: number }; types: Option[]; colorType: number }>();

const { id: attributeId, ...values } = props.attribute;
const form = useForm<AttributeForm>(values);
</script>

<template>
    <PageHeader :title="t('Edit Attribute')" :parents="[{ title: t('Attributes List'), href: route('attributes.index') }]" />
    <form @submit.prevent="form.put(route('attributes.update', attributeId))">
        <AttributeFields :form="form" :types="types" :color-type="colorType" />
        <FormActions :processing="form.processing" :cancel-href="route('attributes.index')" />
    </form>
</template>
