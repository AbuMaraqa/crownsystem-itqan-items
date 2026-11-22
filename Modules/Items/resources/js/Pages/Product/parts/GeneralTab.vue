<script setup lang="ts">
/**
 * The general tab: the measures, pricing type, item type and its aluminium fields, and the item's
 * nature (goods or service) with the service settings. A weight item's colours copy its measures.
 */
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import Field from '@/Components/form/Field.vue';
import MultiSelect from '@/Components/form/MultiSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';

import { isService, isWeight } from './productForm';
import type { ProductForm, ProductFormOptions } from './types';

const props = defineProps<{ form: InertiaForm<ProductForm>; options: ProductFormOptions }>();

const pricingTypes = computed(() => [
    { value: 'simple', label: t('Simple') },
    { value: 'weight', label: t('By Weight') },
]);

const profileShapes = computed(() => [
    { value: 1, label: t('Open') },
    { value: 0, label: t('Closed') },
]);

/** An aluminium item type is priced by weight (the server applies the same rule). */
function chooseProfile(value: unknown): void {
    props.form.item_type_profile_id = (value ?? null) as ProductForm['item_type_profile_id'];

    if (props.options.itemTypeProfiles.find((profile) => String(profile.value) === String(value))?.aluminium) {
        props.form.pricing_type = 'weight';
    }
}
</script>

<template>
    <div class="grid gap-4 md:grid-cols-3">
        <TextInput v-model="form.weight_per_meter" :label="`${t('Weight per Meter')} (${t('kg/m')})`" type="number" step="any" min="0" :error="form.errors.weight_per_meter" />
        <TextInput v-model="form.length" :label="`${t('Length')} (${t('m')})`" type="number" step="any" min="0" :error="form.errors.length" />

        <Field :label="t('Pricing Type')" :error="form.errors.pricing_type">
            <div class="flex h-10 items-center gap-5" role="radiogroup">
                <label v-for="type in pricingTypes" :key="type.value" class="inline-flex items-center gap-2 text-sm">
                    <input v-model="form.pricing_type" type="radio" name="pricing_type" :value="type.value" class="size-4 accent-brand-500" />
                    {{ type.label }}
                </label>
            </div>
        </Field>

        <MultiSelect :model-value="form.item_type_profile_id" single :label="t('Item Type')" :options="options.itemTypeProfiles" :error="form.errors.item_type_profile_id" @update:model-value="chooseProfile" />

        <template v-if="isWeight(form)">
            <TextInput v-model="form.bundle_count" :label="t('Bundle Count')" type="number" step="1" min="0" :error="form.errors.bundle_count" />
            <SelectInput v-model="form.profile_thickness" :label="t('Profile Thickness')" :placeholder="t('Select')" :options="options.profileThicknesses" :error="form.errors.profile_thickness" />
            <SelectInput v-model="form.is_open_profile" :label="t('Profile Shape')" :placeholder="t('Select')" :options="profileShapes" :error="form.errors.is_open_profile" />
            <TextInput v-model="form.area" :label="t('Area')" type="number" step="any" min="0" :error="form.errors.area" />
        </template>

        <SelectInput v-model="form.item_type" :label="t('Item Nature')" :options="options.itemTypes" :error="form.errors.item_type" />

        <TextInput v-model="form.site_area" :label="t('Section Area')" type="number" step="any" :error="form.errors.site_area" />

        <div v-if="isService(form)" class="grid gap-4 md:col-span-3 md:grid-cols-4">
            <SelectInput v-model="form.service_kind" :label="t('Service Kind')" :options="options.serviceKinds" :error="form.errors.service_kind" />
            <SelectInput v-model="form.service_cost_source" :label="t('Default Cost Source')" :options="options.serviceCostSources" :error="form.errors.service_cost_source" />
            <MultiSelect v-model="form.revenue_account_id" single :label="t('Revenue Account')" :placeholder="t('Default account')" :options="options.revenueAccounts" :error="form.errors.revenue_account_id" />
            <MultiSelect v-model="form.cost_account_id" single :label="t('Cost Account')" :placeholder="t('Default account')" :options="options.costAccounts" :error="form.errors.cost_account_id" />
            <p class="text-sm text-muted md:col-span-4">
                {{ t('The service price is the sale price and its cost is the cost price. Pricing type "By Weight" makes the service priced per kg by default.') }}
            </p>
        </div>
    </div>
</template>
