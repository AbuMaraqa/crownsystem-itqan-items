<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue';

import type { Option } from '@/Components/form/SelectInput.vue';
import { controlClasses } from '@/Components/form/controlClasses';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { getJson } from './types';

/**
 * Move: choose the destination category; the moved node takes a new number under it (and, for a
 * category, everything under it too). A category may also go to the root of the tree.
 */
const props = defineProps<{ node: { kind: 'category' | 'product'; id: number; label: string } | null }>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ moved: [] }>();

const search = ref('');
const targets = shallowRef<Option[]>([]);
const target = ref<number | null>(null);
const processing = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function load(): Promise<void> {
    targets.value = await getJson<Option[]>(route('products.tree.move-targets', { q: search.value }));
}

watch(
    () => props.node,
    (node) => {
        if (!node) return;

        search.value = '';
        target.value = null;
        void load();
    },
);

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
});

onBeforeUnmount(() => clearTimeout(timer));

function confirmMove(): void {
    if (!props.node) return;

    const url = props.node.kind === 'category' ? route('products.tree.categories.move', props.node.id) : route('products.tree.items.move', props.node.id);

    router.put(url, { target_id: target.value }, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: (page) => {
            if (!(page.props.flash as { error?: string } | undefined)?.error) {
                open.value = false;
                emit('moved');
            }
        },
    });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('Move')" :description="node?.label">
        <input v-model="search" type="search" :placeholder="t('Search categories')" autocomplete="off" :class="[controlClasses, 'h-10']" />

        <div class="mt-3 max-h-64 overflow-auto rounded-lg border border-line" role="radiogroup" :aria-label="t('Move')">
            <label v-if="node?.kind === 'category'" class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-surface">
                <input v-model="target" type="radio" :value="0" class="accent-brand-500" />
                {{ t('Items Tree') }}
            </label>
            <label v-for="option in targets" :key="option.value" class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-surface">
                <input v-model="target" type="radio" :value="option.value" class="accent-brand-500" />
                {{ option.label }}
            </label>
            <p v-if="!targets.length && node?.kind !== 'category'" class="px-3 py-4 text-center text-sm text-muted">{{ t('No results') }}</p>
        </div>

        <template #footer>
            <Button @click="open = false">{{ t('Back') }}</Button>
            <Button variant="primary" :loading="processing" :disabled="target === null" @click="confirmMove">{{ t('Move') }}</Button>
        </template>
    </Modal>
</template>
