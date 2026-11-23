<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';

import ColorInput from '@/Components/form/ColorInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import { t } from '@/lib/i18n';

export interface FinishForm {
    name: string;
    code: string;
    color_code: string;
    is_active: boolean;
}

/** system: «بلا لون» keeps its name and stays active. */
defineProps<{ form: InertiaForm<FinishForm>; system?: boolean }>();
</script>

<template>
    <div class="grid gap-4">
        <TextInput v-model="form.name" :label="t('Name')" required :disabled="system" :error="form.errors.name" />
        <TextInput v-model="form.code" :label="t('Code')" dir="ltr" :error="form.errors.code" :hint="t('Numbered automatically as in Itqan; you can change it.')" />
        <ColorInput v-model="form.color_code" :label="t('Color')" :error="form.errors.color_code" :hint="t('Shown as a swatch in the online store.')" />
        <Toggle v-if="!system" v-model="form.is_active" :label="t('Is Active')" :hint="t('An inactive colour stays on its items but is not offered for new ones.')" />
    </div>
</template>
