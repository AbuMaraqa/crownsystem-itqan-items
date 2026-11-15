<script setup lang="ts" generic="Line extends GroupedLine">
import { computed } from 'vue';

import { t } from '@/lib/i18n';
import { Package } from '@/lib/icons';

import type { GroupedLine } from './types';

/**
 * The look of the shared items grid for an inventory document's lines: one row per main (weight)
 * item with its sub-items in an always-open panel underneath, and a row of its own for every
 * other item. The page gives the cells after "# | Item | Barcode" through slots, once for a line
 * (`cells`, `nested` inside a panel) and once for a main row's totals (`main`).
 */
const props = withDefaults(
    defineProps<{
        lines: Line[];
        /** Extra header cells after "# | Item | Barcode" (also used by the panels). */
        columns: number;
        empty?: string;
        rowClass?: (line: Line) => string;
    }>(),
    { empty: '', rowClass: () => '' },
);

interface Group {
    key: string;
    main: boolean;
    label: string | null;
    entries: { line: Line; index: number }[];
}

/** Groups in order of first appearance; the indexes stay those of `lines` (the form's items). */
const groups = computed<Group[]>(() => {
    const map = new Map<string, Group>();

    props.lines.forEach((line, index) => {
        const key = line.group_key ? `g:${line.group_key}` : `l:${index}`;

        if (!map.has(key)) map.set(key, { key, main: !!line.group_key, label: line.group_label, entries: [] });
        map.get(key)!.entries.push({ line, index });
    });

    return [...map.values()];
});

const total = computed(() => props.columns + 3);
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-line">
        <table class="w-full min-w-[52rem] border-collapse text-sm">
            <thead>
                <tr class="bg-gray-50 text-xs font-medium text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                    <th class="w-12 px-2 py-2.5 text-center font-medium">#</th>
                    <th class="min-w-56 px-2 py-2.5 text-start font-medium">{{ t('Item') }}</th>
                    <th class="w-32 px-2 py-2.5 text-start font-medium">{{ t('Barcode') }}</th>
                    <slot name="head" />
                </tr>
            </thead>

            <tbody v-for="(group, number) in groups" :key="group.key" class="border-t border-line">
                <template v-if="group.main">
                    <tr class="bg-brand-25/60 dark:bg-brand-500/[0.03]">
                        <td class="px-2 py-2 text-center text-xs text-muted tabular-nums">{{ number + 1 }}</td>
                        <td class="px-2 py-2" colspan="2">
                            <span class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
                                <Package class="size-4 shrink-0 text-brand-500" />{{ group.label }}
                            </span>
                        </td>
                        <slot name="main" :lines="group.entries.map((entry) => entry.line)" />
                    </tr>
                    <tr>
                        <td :colspan="total" class="p-0">
                            <div class="border-s-2 border-brand-300 bg-brand-25 px-3 py-3 dark:border-brand-500/40 dark:bg-brand-500/[0.04]">
                                <div class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                                    {{ t('Sub Items') }} <span class="text-muted tabular-nums">({{ group.entries.length }})</span>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[46rem] border-collapse text-xs">
                                        <thead>
                                            <tr class="text-muted">
                                                <th class="w-12 px-2 py-1 text-center font-medium">#</th>
                                                <th class="px-2 py-1 text-start font-medium">{{ t('Item') }}</th>
                                                <th class="w-28 px-2 py-1 text-start font-medium">{{ t('Barcode') }}</th>
                                                <slot name="subhead" />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(entry, position) in group.entries" :key="entry.index" class="border-t border-line" :class="rowClass(entry.line)">
                                                <td class="px-2 py-1.5 text-center text-muted tabular-nums" dir="ltr">{{ number + 1 }}.{{ position + 1 }}</td>
                                                <td class="px-2 py-1.5">
                                                    <div class="text-gray-800 dark:text-gray-100">{{ entry.line.description }}</div>
                                                    <slot name="details" :line="entry.line" :index="entry.index" />
                                                </td>
                                                <td class="px-2 py-1.5 font-mono text-muted" dir="ltr">{{ entry.line.barcode ?? '' }}</td>
                                                <slot name="cells" :line="entry.line" :index="entry.index" :nested="true" />
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>

                <template v-else>
                    <tr v-for="entry in group.entries" :key="entry.index" class="hover:bg-gray-50/60 dark:hover:bg-white/[0.02]" :class="rowClass(entry.line)">
                        <td class="px-2 py-2 text-center text-xs text-muted tabular-nums">{{ number + 1 }}</td>
                        <td class="px-2 py-2">
                            <div class="text-gray-900 dark:text-white">{{ entry.line.description }}</div>
                            <slot name="details" :line="entry.line" :index="entry.index" />
                        </td>
                        <td class="px-2 py-2 font-mono text-xs text-muted" dir="ltr">{{ entry.line.barcode ?? '' }}</td>
                        <slot name="cells" :line="entry.line" :index="entry.index" :nested="false" />
                    </tr>
                </template>
            </tbody>

            <tbody v-if="!lines.length">
                <tr>
                    <td :colspan="total" class="px-3 py-8 text-center text-sm text-muted">{{ empty }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
