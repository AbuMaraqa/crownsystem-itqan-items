<script setup lang="ts">
/** The card above the tabs: name, slug, categories and the lists an item belongs to. */
import type { InertiaForm } from '@inertiajs/vue3';
import QuickCreateModal from '@modules/Accounting/resources/js/Pages/parts/QuickCreateModal.vue';
import { computed, ref, watch } from 'vue';

import Field from '@/Components/form/Field.vue';
import MultiSelect from '@/Components/form/MultiSelect.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import RichEditor from '@/Components/form/RichEditor.vue';
import TagsInput from '@/Components/form/TagsInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { can, t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';

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

// A category made with the "+" button joins the list and is selected at once, without reloading the page.
const creatingCategory = ref(false);
const createdCategories = ref<Option[]>([]);
const categoryOptions = computed(() => [...props.options.categories, ...createdCategories.value]);

function onCategoryCreated(option: Option): void {
    createdCategories.value.push(option);
    props.form.category_id = [...(props.form.category_id ?? []), option.value];
}

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
            <!-- One field (label beside it in a wide card), its control a select with a "+" that makes a category in place. -->
            <Field class="md:col-span-2" :label="t('Category')" :required="categoryRequired">
                <div class="flex items-start gap-2">
                    <MultiSelect v-model="form.category_id" class="min-w-0 flex-1" :options="categoryOptions" :error="firstError('category_id')" />
                    <button
                        v-if="can('Category Create')"
                        type="button"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-control border border-line text-muted hover:border-brand-300 hover:text-brand-600 dark:hover:text-brand-400"
                        :aria-label="t('Add a category')"
                        :title="t('Add a category')"
                        @click="creatingCategory = true"
                    >
                        <Plus class="size-4" />
                    </button>
                </div>
                <QuickCreateModal v-if="can('Category Create')" v-model:open="creatingCategory" :url="route('categories.quick-store')" :title="t('Add a category')" fields="name" @created="onCategoryCreated" />
            </Field>
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
