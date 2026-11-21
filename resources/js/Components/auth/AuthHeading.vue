<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

/** The top of every sign-in screen: the tab title, a centred heading and an optional line under it. */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <Head :title="title" />
    <div class="text-center">
        <h1 class="text-lg leading-none font-medium text-gray-950 dark:text-white">
            {{ title }}
        </h1>
        <p v-if="description" class="mt-2.5 text-sm text-gray-700 dark:text-gray-300">
            {{ description }}
        </p>
    </div>
</template>
