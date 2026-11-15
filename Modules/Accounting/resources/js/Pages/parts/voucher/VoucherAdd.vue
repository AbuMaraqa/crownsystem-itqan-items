<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { VoucherForm, VoucherFormOptions, VoucherKind } from './types';
import VoucherFields from './VoucherFields.vue';

const props = defineProps<{ kind: VoucherKind; voucher: VoucherForm & { reference_no: string }; options: VoucherFormOptions }>();

const { reference_no: referenceNo, ...values } = props.voucher;
const form = useForm<VoucherForm>(values);
</script>

<template>
    <PageHeader :title="t(kind.add)" :parents="[{ title: t(kind.list), href: route(`${kind.routes}.index`) }]" />
    <form @submit.prevent="form.post(route(`${kind.routes}.store`))">
        <VoucherFields :form="form" :options="options" :kind="kind" :reference-no="referenceNo" :status-label="t('Draft')" />
        <FormActions :processing="form.processing" :cancel-href="route(`${kind.routes}.index`)" :submit-label="t('Save')" />
    </form>
</template>
