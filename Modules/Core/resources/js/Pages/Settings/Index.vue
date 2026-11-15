<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import type { ExistingImage } from '@/Components/form/ImageUpload.vue';
import FormActions from '@/Components/form/FormActions.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';

import AdditionalCostsTab from './parts/AdditionalCostsTab.vue';
import AppVersionsTab from './parts/AppVersionsTab.vue';
import GeneralTab from './parts/GeneralTab.vue';
import type { AppVersionForm, CostTypeForm, FontOption, SettingsForm } from './parts/types';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    settings: Omit<SettingsForm, '_method' | 'logo' | 'remove_logo' | 'app_versions' | 'additional_cost_types'>;
    currencyOptions: Option[];
    colorPresets: string[];
    fonts: FontOption[];
    fontPreviewUrl: string;
    logo: ExistingImage[];
    appVersions: AppVersionForm[];
    additionalCostTypes: CostTypeForm[];
}>();

const form = useForm<SettingsForm>({
    _method: 'put',
    ...props.settings,
    logo: null,
    remove_logo: false,
    app_versions: props.appVersions.map((version) => ({ ...version })),
    additional_cost_types: props.additionalCostTypes.map((type) => ({ ...type })),
});

const active = ref('general');

const tabs = computed(() => {
    const keys = Object.keys(form.errors);
    const inTab = (prefix: string) => keys.some((key) => key.startsWith(prefix));

    return [
        { value: 'general', label: t('General Site Info'), hasError: keys.some((key) => !key.startsWith('app_versions') && !key.startsWith('additional_cost_types')) },
        { value: 'app_versions', label: t('App Versions'), hasError: inTab('app_versions') },
        { value: 'additional_costs', label: t('Additional Costs'), hasError: inTab('additional_cost_types') },
    ];
});

function submit(): void {
    form.post(route('settings.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset('logo', 'remove_logo'),
    });
}
</script>

<template>
    <Head><link rel="stylesheet" :href="fontPreviewUrl" /></Head>
    <PageHeader :title="t('Settings')" />
    <form @submit.prevent="submit">
        <Tabs v-model="active" :tabs="tabs">
            <template #general><GeneralTab :form="form" :logo="logo" :color-presets="colorPresets" :fonts="fonts" :currency-options="currencyOptions" /></template>
            <template #app_versions><AppVersionsTab :form="form" /></template>
            <template #additional_costs><AdditionalCostsTab :form="form" /></template>
        </Tabs>
        <FormActions v-if="can('Setting Update')" :processing="form.processing" />
    </form>
</template>
