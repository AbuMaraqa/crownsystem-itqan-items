<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Check } from '@/lib/icons';

import type { SubItem } from '../types';

/**
 * The sub-items under a main item, as in the old system's screen:
 * # | Item | Barcode | Piece Count | Piece Length | Weight per Meter | Total Weight.
 * In the picker each row can be ticked (`selection`, bound to `selected`); a row already added
 * shows a check, a row matching the search is highlighted.
 */
const props = withDefaults(
    defineProps<{
        subItems: SubItem[];
        number: number;
        matched?: number[];
        selection?: { key: string; added: boolean }[] | null;
    }>(),
    { matched: () => [], selection: null },
);

const selected = defineModel<string[]>('selected', { default: () => [] });

const quantity = (value: number | null): string => (value === null ? '—' : value.toLocaleString('en', { maximumFractionDigits: 3 }));

function toggle(key: string, checked: boolean): void {
    selected.value = checked ? [...new Set([...selected.value, key])] : selected.value.filter((item) => item !== key);
}

const pick = (index: number) => props.selection?.[index] ?? null;
</script>

<template>
    <div class="border-s-2 border-brand-500/40 bg-brand-500/[0.03] px-3 py-2">
        <p class="mb-1.5 text-xs font-medium text-muted">{{ t('Sub Items') }} ({{ subItems.length }})</p>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="text-xs text-muted">
                    <tr class="border-b border-line">
                        <th v-if="selection" class="w-10 px-2 py-1"><span class="sr-only">{{ t('Select') }}</span></th>
                        <th class="w-12 px-2 py-1 text-start font-medium">#</th>
                        <th class="px-2 py-1 text-start font-medium">{{ t('Item') }}</th>
                        <th class="px-2 py-1 text-start font-medium">{{ t('Barcode') }}</th>
                        <th class="w-24 px-2 py-1 text-end font-medium">{{ t('Piece Count') }}</th>
                        <th class="w-24 px-2 py-1 text-end font-medium">{{ t('Piece Length') }}</th>
                        <th class="w-28 px-2 py-1 text-end font-medium">{{ t('Weight per Meter') }}</th>
                        <th class="w-24 px-2 py-1 text-end font-medium">{{ t('Total Weight') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(subItem, index) in subItems"
                        :key="index"
                        class="border-b border-line/60 last:border-0"
                        :class="[matched.includes(index) ? 'bg-warning-50 dark:bg-warning-500/10' : '', pick(index)?.added ? 'opacity-60' : '']"
                    >
                        <td v-if="selection" class="px-2 py-1.5 text-center">
                            <template v-if="pick(index)">
                                <Check v-if="pick(index)!.added" class="mx-auto size-4 text-success" :aria-label="t('Already added')" />
                                <input
                                    v-else
                                    type="checkbox"
                                    class="size-4 cursor-pointer rounded border-gray-300 text-brand-500 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-800"
                                    :checked="selected.includes(pick(index)!.key)"
                                    :aria-label="`${t('Select')} ${subItem.name}`"
                                    @change="toggle(pick(index)!.key, ($event.target as HTMLInputElement).checked)"
                                />
                            </template>
                        </td>
                        <td class="px-2 py-1.5 font-mono text-xs text-muted tabular-nums" dir="ltr">{{ number }}.{{ index + 1 }}</td>
                        <td class="px-2 py-1.5">{{ subItem.name }}</td>
                        <td class="px-2 py-1.5 font-mono text-xs" dir="ltr">{{ subItem.barcode }}</td>
                        <td class="px-2 py-1.5 text-end font-mono tabular-nums" dir="ltr">{{ quantity(subItem.count) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono tabular-nums" dir="ltr">{{ quantity(subItem.length) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono tabular-nums" dir="ltr">{{ quantity(subItem.weight_per_meter) }}</td>
                        <td class="px-2 py-1.5 text-end font-mono font-semibold tabular-nums" dir="ltr">{{ quantity(subItem.total_weight) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
