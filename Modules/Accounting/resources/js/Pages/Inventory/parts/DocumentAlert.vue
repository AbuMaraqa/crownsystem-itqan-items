<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { CircleAlert } from '@/lib/icons';

/**
 * Why the service refused the last action (InventoryDocumentPages::refused), every reason on its
 * own line: e.g. each item short of stock with what is available and what is required.
 */
const page = usePage();
const lines = computed(() => {
    const message = (page.props.errors as Record<string, string> | undefined)?.document;

    return message ? message.split('\n').filter(Boolean) : [];
});
</script>

<template>
    <div
        v-if="lines.length"
        class="flex items-start gap-3 rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-sm text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400"
        role="alert"
    >
        <CircleAlert class="mt-0.5 size-5 shrink-0" />
        <ul class="space-y-1">
            <li v-for="(line, index) in lines" :key="index" :class="index === 0 && lines.length > 1 ? 'font-semibold' : ''">{{ line }}</li>
        </ul>
    </div>
</template>
