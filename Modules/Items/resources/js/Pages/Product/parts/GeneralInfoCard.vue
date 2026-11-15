<script setup lang="ts">
/** The card above the tabs: name, slug, categories and the lists an item belongs to. */
import type { InertiaForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import RichEditor from '@/Components/form/RichEditor.vue';
import TagsInput from '@/Components/form/TagsInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

import { isWeight } from './productForm';
import type { ProductForm, ProductFormOptions } from './types';

const props = defineProps<{ form: InertiaForm<ProductForm>; options: ProductFormOptions; categoryRequired?: boolean }>();

// A new name makes a new slug, as on the old page: the server makes it unique (a piece's name repeats).
watch(
    () => props.form.name,
    () => {
        props.form.slug = '';
    },
);

function firstError(prefix: string): string | undefined {
    const errors = props.form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((item) => item === prefix || item.startsWith(`${prefix}.`));

    return key ? errors[key] : undefined;
}
</script>

<template>
    <Card :title="t('General information')">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <TextInput v-model="form.name" class="xl:col-span-2" :label="t('Name')" required :error="form.errors.name" />
            <TextInput v-model="form.slug" class="xl:col-span-2" :label="t('Slug')" dir="ltr" :placeholder="t('Keep empty for auto generated')" :error="form.errors.slug" />
            <MultiSelect v-model="form.category_id" class="md:col-span-2" :label="t('Category')" :required="categoryRequired" :options="options.categories" :error="firstError('category_id')" />
            <MultiSelect v-model="form.unit_id" single :label="t('Unit')" :options="options.units" :error="form.errors.unit_id" />
            <template v-if="!isWeight(form)">
                <MultiSelect v-model="form.brand_id" single :label="t('Brand')" :options="options.brands" :error="form.errors.brand_id" />
                <TagsInput v-model="form.tags" :label="t('Tags')" :suggestions="options.tagSuggestions" :error="firstError('tags')" />
                <TagsInput v-model="form.labels" :label="t('Labels')" :suggestions="options.labelSuggestions" :error="firstError('labels')" />
                <MultiSelect v-model="form.manufacturers" class="md:col-span-2" :label="t('Manufacturers')" :options="options.manufacturers" :error="firstError('manufacturers')" />
            </template>
            <MultiSelect v-model="form.branches" class="md:col-span-2 xl:col-span-4" :label="t('Branches')" :options="options.branches" :error="firstError('branches')" />
            <TextareaInput v-model="form.short_description" class="md:col-span-2 xl:col-span-4" :label="t('Short Description')" :error="form.errors.short_description" />
            <RichEditor v-model="form.description" class="md:col-span-2 xl:col-span-4" :label="t('Description')" :error="form.errors.description" />
        </div>
    </Card>
</template>
