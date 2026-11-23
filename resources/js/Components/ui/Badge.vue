<script setup lang="ts">
import { toneBadge as tones, type Tone } from '@/lib/tones';

withDefaults(
    defineProps<{
        tone?: Tone;
        /** A small dot before the text, as a status. */
        dot?: boolean;
    }>(),
    { tone: 'neutral', dot: false },
);
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs leading-4 font-medium whitespace-nowrap" :class="tones[tone]">
        <span v-if="dot" class="size-1.5 rounded-full bg-current" aria-hidden="true" />
        <slot />
    </span>
</template>
