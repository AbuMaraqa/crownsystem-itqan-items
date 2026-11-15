<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Trash2 } from '@/lib/icons';

/**
 * One cost line of a production order: its number, its fields and its total (from the server's
 * cost preview), with a remove button while the order is open.
 */
defineProps<{ number: number; total: number | undefined; title?: string | null; editable: boolean }>();

const emit = defineEmits<{ remove: [] }>();

const money = (value: number | undefined): string => (value ?? 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <div class="rounded-xl border border-line bg-surface/40 p-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2">
                <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-md bg-brand-500 text-xs font-bold text-white tabular-nums">{{ number }}</span>
                <span v-if="title" class="truncate text-sm font-medium">{{ title }}</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-muted">{{ t('Total Cost') }}:</span>
                <span class="font-mono text-sm font-semibold tabular-nums" dir="ltr">{{ money(total) }}</span>
                <button v-if="editable" type="button" class="rounded p-1 text-muted hover:bg-error-50 hover:text-danger dark:hover:bg-error-500/10" :aria-label="t('Remove')" @click="emit('remove')">
                    <Trash2 class="size-4" />
                </button>
            </div>
        </div>
        <slot />
    </div>
</template>
