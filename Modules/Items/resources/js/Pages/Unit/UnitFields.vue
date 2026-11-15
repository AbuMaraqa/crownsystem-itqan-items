<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';

import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';

export interface UnitForm {
    name: string;
    slug: string;
    description: string;
}

defineProps<{ form: InertiaForm<UnitForm> }>();
</script>

<template>
    <div class="grid gap-4">
        <TextInput v-model="form.name" :label="t('Name')" required :error="form.errors.name" />
        <TextInput v-model="form.slug" :label="t('Slug')" dir="ltr" :error="form.errors.slug" :hint="t('Leave empty to make it from the name')" />
        <TextareaInput v-model="form.description" :label="t('Description')" :error="form.errors.description" />
    </div>
</template>
