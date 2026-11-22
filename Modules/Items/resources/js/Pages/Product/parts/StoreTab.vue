<script setup lang="ts">
/**
 * What the online store shows of the item, in one place so the rest of the card stays for sales and the
 * warehouse: its link, texts and images, and for a non-weight item its brand, tags, labels, makers and
 * addons (a weight item has none; the server drops them too). The colours reach the store as variations.
 */
import type { InertiaForm } from '@inertiajs/vue3';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import RichEditor from '@/Components/form/RichEditor.vue';
import TagsInput from '@/Components/form/TagsInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';

import AddonsTab from './AddonsTab.vue';
import MediaFields from './MediaFields.vue';
import { isWeight } from './productForm';
import type { ErrorOf, ProductForm, ProductFormOptions } from './types';

const props = defineProps<{ form: InertiaForm<ProductForm>; options: ProductFormOptions; error: ErrorOf; collapsed?: boolean }>();

function firstError(prefix: string): string | undefined {
    const errors = props.form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((item) => item === prefix || item.startsWith(`${prefix}.`));

    return key ? errors[key] : undefined;
}
</script>

<template>
    <div class="grid gap-6">
        <div class="grid gap-4 md:grid-cols-2">
            <TextInput v-model="form.slug" class="md:col-span-2" :label="t('Slug')" dir="ltr" :placeholder="t('Keep empty for auto generated')" :error="form.errors.slug" />
            <template v-if="!isWeight(form)">
                <MultiSelect v-model="form.brand_id" single :label="t('Brand')" :options="options.brands" :error="form.errors.brand_id" />
                <MultiSelect v-model="form.manufacturers" :label="t('Manufacturers')" :options="options.manufacturers" :error="firstError('manufacturers')" />
                <TagsInput v-model="form.tags" :label="t('Tags')" :suggestions="options.tagSuggestions" :error="firstError('tags')" />
                <TagsInput v-model="form.labels" :label="t('Labels')" :suggestions="options.labelSuggestions" :error="firstError('labels')" />
            </template>
            <TextareaInput v-model="form.short_description" class="md:col-span-2" :label="t('Short Description')" :error="form.errors.short_description" />
            <RichEditor v-model="form.description" class="md:col-span-2" :label="t('Description')" :error="form.errors.description" />
        </div>

        <MediaFields :row="form" :error="error" />

        <section v-if="!isWeight(form)">
            <h3 class="mb-3 text-sm font-semibold">{{ t('Addons') }}</h3>
            <AddonsTab :form="form" :addons="options.addons" :collapsed="collapsed" />
        </section>
    </div>
</template>
