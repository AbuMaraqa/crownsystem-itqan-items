<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * A user (or customer, supplier) in a table: avatar, name, and a second line (email or phone).
 * The Vue side of `user_display_html` / UserColumn: every list shows people this way.
 * The server sends `avatar` from `$user->avatar_url`.
 */
defineProps<{ name: string; avatar: string; detail?: string | null; href?: string | null }>();
</script>

<template>
    <span class="flex min-w-0 items-center gap-2.5">
        <img :src="avatar" alt="" width="32" height="32" class="size-8 shrink-0 rounded-full object-cover" />
        <span class="min-w-0">
            <Link v-if="href" :href="href" class="block truncate font-medium text-brand-600 hover:underline dark:text-brand-400">{{ name }}</Link>
            <span v-else class="block truncate font-medium text-ink">{{ name }}</span>
            <span v-if="detail" class="block truncate text-xs text-muted" dir="ltr">{{ detail }}</span>
        </span>
    </span>
</template>
