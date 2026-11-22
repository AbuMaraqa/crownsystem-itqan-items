<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';

import Field from '@/Components/form/Field.vue';
import ImageUpload, { type ExistingImage } from '@/Components/form/ImageUpload.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import TreeSelect from '@/Components/form/TreeSelect.vue';
import type { TreeNode } from '@/Components/form/TreeSelectNode.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

export interface CategoryForm {
    name: string;
    slug: string;
    parent_id: number | string | null;
    description: string;
    /** How the category's items are priced: by quantity, or by weight (a kg price per colour). */
    pricing_type: 'simple' | 'weight';
    image: File | File[] | null;
    removed_image: (number | string)[];
}

defineProps<{
    form: InertiaForm<CategoryForm>;
    parents: TreeNode[];
    images?: ExistingImage[];
    /** The category number from the item tree, made by the server from the parent's number. */
    code?: string | null;
}>();

const pricingTypes = [
    { value: 'simple', label: t('Simple') },
    { value: 'weight', label: t('By Weight') },
];
</script>

<template>
    <div class="grid items-start gap-4 lg:grid-cols-3">
        <Card class="lg:col-span-2">
            <div class="grid gap-4 md:grid-cols-2">
                <TextInput v-model="form.name" :label="t('Name')" required :error="form.errors.name" />
                <TextInput v-model="form.slug" :label="t('Slug')" dir="ltr" :error="form.errors.slug" :hint="t('Leave empty to make it from the name')" />
                <TextInput v-if="code !== undefined" :model-value="code ?? '-'" :label="t('Code')" dir="ltr" disabled class="md:col-span-2" />
                <TreeSelect
                    v-model="form.parent_id"
                    class="md:col-span-2"
                    :nodes="parents"
                    :label="t('Parent Category')"
                    :placeholder="t('Select Parent Category')"
                    :error="form.errors.parent_id"
                />
                <Field class="md:col-span-2" :label="t('Pricing Type')" :error="form.errors.pricing_type" :hint="t('By weight: the items are priced per kg, by the kg price of each colour below.')">
                    <div class="flex h-control items-center gap-5" role="radiogroup">
                        <label v-for="type in pricingTypes" :key="type.value" class="inline-flex items-center gap-2 text-sm">
                            <input v-model="form.pricing_type" type="radio" name="pricing_type" :value="type.value" class="size-4 accent-brand-500" />
                            {{ type.label }}
                        </label>
                    </div>
                </Field>
                <TextareaInput v-model="form.description" class="md:col-span-2" :label="t('Description')" :error="form.errors.description" />
            </div>
        </Card>
        <Card>
            <ImageUpload v-model="form.image" v-model:removed="form.removed_image" :existing="images" :label="t('Image')" :error="form.errors.image" />
        </Card>
    </div>
</template>
