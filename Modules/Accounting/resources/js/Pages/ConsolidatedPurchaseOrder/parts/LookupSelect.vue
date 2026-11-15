<script setup lang="ts">
import { computed, ref } from 'vue';

import SelectInput from '@/Components/form/SelectInput.vue';
import { t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';
import { route } from '@/lib/route';

import QuickCreateModal from '../../parts/QuickCreateModal.vue';
import type { Option } from '../../parts/types';

/**
 * A short list (cost items, attachment categories, reappraisal elements) with a "+" that creates a
 * new entry in place (`accounting.consolidated-purchase-orders.lookups.store`) and selects it.
 */
const props = defineProps<{ options: Option[]; type: 'additional-cost-type' | 'attachment-category' | 'reappraisal-element'; label?: string; error?: string; required?: boolean; placeholder?: string }>();

const model = defineModel<string | number | null>();

const created = ref<Option[]>([]);
const creating = ref(false);
const all = computed(() => [...props.options, ...created.value.filter((option) => !props.options.some((item) => String(item.value) === String(option.value)))]);

function onCreated(option: Option): void {
    created.value.push(option);
    model.value = option.value;
}
</script>

<template>
    <div class="flex items-end gap-2">
        <div class="min-w-0 flex-1">
            <SelectInput v-model="model" :label="label" :options="all" :placeholder="placeholder ?? t('Select')" :error="error" :required="required" />
        </div>
        <button
            type="button"
            class="mb-px inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-line text-muted hover:border-brand-300 hover:text-brand-600 dark:hover:text-brand-400"
            :class="{ 'mb-7': error }"
            :aria-label="t('Create')"
            :title="t('Create')"
            @click="creating = true"
        >
            <Plus class="size-4" />
        </button>
        <QuickCreateModal v-model:open="creating" :url="route('accounting.consolidated-purchase-orders.lookups.store')" :title="t('Create')" fields="name" :body="{ type }" @created="onCreated" />
    </div>
</template>
