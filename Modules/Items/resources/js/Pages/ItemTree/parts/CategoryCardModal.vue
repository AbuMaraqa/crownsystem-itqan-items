<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

import Field from '@/Components/form/Field.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { Check, Returns } from '@/lib/icons';
import { route } from '@/lib/route';

import { getJson, type CategoryCardRequest } from './types';

/**
 * The category card: its number (automatic from its parent's number, read only), its name and the
 * category it belongs to. A new category goes under the chosen node.
 */
const props = defineProps<{ request: CategoryCardRequest | null }>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ name: '', parent_id: null as number | null });
const categoryId = ref<number | null>(null);
const code = ref<string | null>(null);
const parent = ref<{ code: string | null; name: string } | null>(null);
const loading = ref(false);

watch(
    () => props.request,
    async (request) => {
        if (!request) return;

        form.clearErrors();
        categoryId.value = request.id ?? null;

        if (request.id) {
            loading.value = true;

            try {
                const data = await getJson<{ code: string | null; name: string; parent: { code: string | null; name: string } | null }>(route('products.tree.categories.show', request.id));
                code.value = data.code;
                parent.value = data.parent;
                form.defaults({ name: data.name, parent_id: null });
            } finally {
                loading.value = false;
            }
        } else {
            code.value = null;
            parent.value = request.parent ?? null;
            form.defaults({ name: '', parent_id: request.parentId ?? null });
        }

        form.reset();
    },
);

function submit(): void {
    const options = { preserveState: true, preserveScroll: true, onSuccess: () => (open.value = false) };

    if (categoryId.value) form.put(route('products.tree.categories.update', categoryId.value), options);
    else form.post(route('products.tree.categories.store'), options);
}
</script>

<template>
    <Modal v-model:open="open" :title="categoryId ? t('Edit Group') : t('New Category')" width="max-w-3xl">
        <form id="category-card-form" class="grid gap-4 md:grid-cols-4" :class="{ 'opacity-60': loading }" @submit.prevent="submit">
            <TextInput :model-value="code ?? ''" :label="t('Category Number')" :placeholder="t('Automatic')" disabled dir="ltr" />
            <div class="md:col-span-2">
                <TextInput v-model="form.name" :label="t('Category Name')" :error="form.errors.name" required :maxlength="255" />
            </div>
            <Field :label="t('Belongs to Category')">
                <div class="flex h-10 items-center rounded-lg bg-gray-50 px-3 text-sm text-gray-600 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-400 dark:ring-white/20">
                    {{ parent ? `${parent.code ?? ''} ${parent.name}`.trim() : '—' }}
                </div>
            </Field>
        </form>

        <template #footer>
            <Button @click="open = false"><Returns class="size-4" />{{ t('Back') }}</Button>
            <Button variant="primary" type="submit" form="category-card-form" :loading="form.processing"><Check class="size-4" />{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
