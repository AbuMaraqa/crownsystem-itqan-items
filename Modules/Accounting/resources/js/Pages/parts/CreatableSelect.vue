<script setup lang="ts">
import { computed, ref } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import { t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';

import QuickCreateModal from './QuickCreateModal.vue';
import type { Option } from './types';

/**
 * A searched choice (RemoteSelect) with a "+" that creates a new one in place (QuickCreateModal)
 * and selects it: a supplier, a shipping company, a cost item... Without `createUrl` it is a plain
 * RemoteSelect.
 */
const props = withDefaults(
    defineProps<{
        url: string;
        label: string;
        initial?: Option[];
        error?: string;
        hint?: string;
        required?: boolean;
        createUrl?: string;
        createTitle?: string;
        createFields?: 'party' | 'name';
        createBody?: Record<string, unknown>;
    }>(),
    { initial: () => [], createFields: 'party', createBody: () => ({}) },
);

const emit = defineEmits<{ picked: [option: Option] }>();

const model = defineModel<string | number | null>();

const created = ref<Option[]>([]);
const version = ref(0);
const creating = ref(false);

const options = computed(() => [...created.value, ...props.initial.filter((option) => !created.value.some((item) => String(item.value) === String(option.value)))]);

function onCreated(option: Option): void {
    created.value = [option];
    model.value = option.value;
    version.value++;
    emit('picked', option);
}
</script>

<template>
    <div class="flex items-end gap-2">
        <div class="min-w-0 flex-1">
            <RemoteSelect
                :key="version"
                v-model="model"
                :url="url"
                :initial="options"
                :label="label"
                :placeholder="t('Select')"
                :error="error"
                :hint="hint"
                :required="required"
                @picked="(option: Option) => emit('picked', option)"
            />
        </div>
        <button
            v-if="createUrl"
            type="button"
            class="mb-px inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-line text-muted hover:border-brand-300 hover:text-brand-600 dark:hover:text-brand-400"
            :class="{ 'mb-7': error || hint }"
            :aria-label="createTitle ?? t('Create')"
            :title="createTitle ?? t('Create')"
            @click="creating = true"
        >
            <Plus class="size-4" />
        </button>
        <QuickCreateModal v-if="createUrl" v-model:open="creating" :url="createUrl" :title="createTitle ?? t('Create')" :fields="createFields" :body="createBody" @created="onCreated" />
    </div>
</template>
