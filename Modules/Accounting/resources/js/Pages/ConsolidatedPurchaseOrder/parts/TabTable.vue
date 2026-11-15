<script setup lang="ts" generic="Row extends { id: number }">
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { Inbox, Plus } from '@/lib/icons';

/**
 * The table of one tab of the consolidated purchase order page: its records, an add button, and
 * per-row actions (`#actions`). Cells are `#cell-<key>` slots, or the row's value as text.
 */
defineProps<{
    title: string;
    rows: Row[];
    columns: { key: string; label: string; class?: string }[];
    addLabel?: string;
    canAdd?: boolean;
    emptyText?: string;
}>();

const emit = defineEmits<{ add: [] }>();

const text = (row: Row, key: string): string => {
    const value = (row as Record<string, unknown>)[key];

    return value === null || value === undefined || value === '' ? '—' : String(value);
};
</script>

<template>
    <Card :title="title">
        <template v-if="canAdd && addLabel" #actions>
            <Button variant="primary" size="sm" @click="emit('add')"><Plus />{{ addLabel }}</Button>
        </template>

        <div v-if="!rows.length" class="flex flex-col items-center justify-center gap-2 py-10 text-center text-muted">
            <Inbox class="size-8" />
            <p class="text-sm">{{ emptyText ?? t('No data available') }}</p>
        </div>
        <div v-else class="-mx-5 -my-5 overflow-x-auto">
            <table class="w-full min-w-[48rem] text-sm">
                <thead class="bg-surface text-xs text-muted">
                    <tr>
                        <th v-for="column in columns" :key="column.key" class="px-4 py-2.5 text-start font-medium whitespace-nowrap" :class="column.class">{{ column.label }}</th>
                        <th v-if="$slots.actions" class="w-12 px-2 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="border-t border-line align-top hover:bg-surface/50">
                        <td v-for="column in columns" :key="column.key" class="px-4 py-3" :class="column.class">
                            <slot :name="`cell-${column.key}`" :row="row">{{ text(row, column.key) }}</slot>
                        </td>
                        <td v-if="$slots.actions" class="px-2 py-2"><slot name="actions" :row="row" /></td>
                    </tr>
                </tbody>
                <tfoot v-if="$slots.footer">
                    <slot name="footer" />
                </tfoot>
            </table>
        </div>
    </Card>
</template>
