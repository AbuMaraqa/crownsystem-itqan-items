<script setup lang="ts">
import { inject } from 'vue';

import { t } from '@/lib/i18n';
import { CircleAlert, QrCode } from '@/lib/icons';

import { cellInput } from './classes';
import { gridKey } from './useItemsGrid';

/**
 * Barcode entry above the grid: Enter adds the item, or raises its quantity by the barcode's pack
 * quantity; a piece goes under its main item. A barcode on several items asks which one.
 */
const grid = inject(gridKey)!;
const scan = grid.scan;
</script>

<template>
    <div class="relative w-full max-w-md">
        <div class="relative">
            <QrCode class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
            <input
                v-model="scan.code"
                type="text"
                autocomplete="off"
                dir="ltr"
                :aria-label="t('Barcode')"
                :placeholder="t('Barcode')"
                :class="[cellInput, 'h-10 ps-9 font-mono']"
                :aria-busy="scan.busy"
                @keydown.enter.prevent.stop="grid.scanBarcode(t('No item has this barcode.'))"
            />
        </div>

        <div v-if="scan.matches.length" class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-line bg-card py-1 text-sm shadow-theme-lg">
            <p class="px-3 py-1.5 text-xs text-muted">{{ t('This barcode is on several items; choose one.') }}</p>
            <button
                v-for="match in scan.matches"
                :key="match.id"
                type="button"
                class="block w-full px-3 py-2 text-start hover:bg-brand-50 dark:hover:bg-brand-500/15"
                @click="grid.pickScanned(match)"
            >
                <span class="block truncate">{{ match.label }}</span>
            </button>
        </div>

        <p v-if="scan.message" class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-warning-700 dark:text-warning-400">
            <CircleAlert class="size-4" />
            {{ scan.message }}
            <a v-if="scan.addUrl" :href="scan.addUrl" target="_blank" rel="noopener" class="underline">{{ t('Add Item') }}</a>
        </p>
    </div>
</template>
