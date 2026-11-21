<script setup lang="ts">
defineProps<{
    label?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    id?: string;
}>();
</script>

<template>
    <div data-field class="min-w-0">
        <!-- `aside`: a link on the label's line, at its far edge ("Forgot password?"). -->
        <div v-if="label && $slots.aside" class="mb-2 flex items-center justify-between gap-2">
            <label :for="id" class="block text-sm leading-4 text-gray-950 dark:text-gray-100"> {{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span> </label>
            <slot name="aside" />
        </div>
        <label v-else-if="label" :for="id" class="mb-2 block text-sm leading-4 text-gray-950 dark:text-gray-100">
            {{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span>
        </label>
        <slot />
        <p v-if="error" class="mt-1 text-sm text-danger" role="alert">
            {{ error }}
        </p>
        <p v-else-if="hint" class="mt-1 text-sm text-muted">{{ hint }}</p>
    </div>
</template>
