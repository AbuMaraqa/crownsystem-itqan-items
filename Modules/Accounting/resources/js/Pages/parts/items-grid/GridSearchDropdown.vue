<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Layers } from '@/lib/icons';

import type { Anchor } from './useItemsGrid';
import type { SearchResult } from './types';

/**
 * A search result list under a grid cell. It is teleported to the body and fixed to the screen,
 * so no scrolling container or blurred sticky bar can clip or shift it. The buttons use
 * `mousedown.prevent`, so the field keeps the focus while a result is picked.
 */
defineProps<{
    open: boolean;
    anchor: Anchor | null;
    results: SearchResult[];
    highlight: number;
    loading: boolean;
    /** Item / Group tabs above the list (the item cell only). */
    tabs?: { mode: string; label: string }[];
    mode?: string;
    compact?: boolean;
}>();

const emit = defineEmits<{ choose: [result: SearchResult]; hover: [index: number]; mode: [mode: string] }>();
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && anchor"
            role="listbox"
            class="fixed z-[70] flex flex-col overflow-hidden rounded-xl border border-line bg-card shadow-theme-lg"
            :class="compact ? 'max-h-60' : 'max-h-80'"
            :style="{ top: `${anchor.top}px`, left: `${anchor.left}px`, width: `${anchor.width}px` }"
        >
            <div v-if="tabs?.length" class="flex shrink-0 gap-1 border-b border-line bg-gray-50 p-1 dark:bg-white/[0.03]">
                <button
                    v-for="tab in tabs"
                    :key="tab.mode"
                    type="button"
                    class="flex-1 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                    :class="mode === tab.mode ? 'bg-card text-brand-600 shadow-theme-xs dark:text-brand-400' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'"
                    @mousedown.prevent="emit('mode', tab.mode)"
                >
                    {{ tab.label }}
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto py-1">
                <button
                    v-for="(result, index) in results"
                    :key="result.id"
                    type="button"
                    role="option"
                    :aria-selected="index === highlight"
                    class="flex w-full items-center justify-between gap-3 px-3 text-start"
                    :class="[compact ? 'py-1.5 text-xs' : 'py-2 text-sm', index === highlight ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'text-gray-700 dark:text-gray-200']"
                    @mousedown.prevent="emit('choose', result)"
                    @mousemove="emit('hover', index)"
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <Layers v-if="mode === 'category'" class="size-4 shrink-0 text-brand-500" />
                        <span class="min-w-0">
                            <span class="block truncate">{{ result.label }}</span>
                            <span v-if="result.main_label" class="block truncate text-[11px] text-muted">{{ t('Inside main item:') }} {{ result.main_label }}</span>
                        </span>
                    </span>
                    <span v-if="result.barcode" class="shrink-0 font-mono text-xs text-muted" dir="ltr">{{ result.barcode }}</span>
                </button>

                <p v-if="loading" class="px-3 py-2 text-sm text-muted">{{ t('Searching...') }}</p>
                <p v-else-if="results.length === 0" class="px-3 py-2 text-sm text-muted">{{ t('No results') }}</p>
            </div>
        </div>
    </Teleport>
</template>
