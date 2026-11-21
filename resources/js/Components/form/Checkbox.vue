<script setup lang="ts">
import { useId } from 'vue';

/** A checkbox with its label beside it. */
defineProps<{ label?: string; disabled?: boolean }>();

const model = defineModel<boolean>();
const id = useId();
</script>

<template>
    <div class="flex items-center gap-2.5">
        <input
            :id="id"
            v-model="model"
            type="checkbox"
            :disabled="disabled"
            class="size-[18px] shrink-0 cursor-pointer rounded-[5px] border border-gray-300 accent-brand-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700"
        />
        <label v-if="label" :for="id" class="cursor-pointer text-sm font-medium text-gray-950 select-none dark:text-gray-100">{{ label }}</label>
    </div>
</template>
