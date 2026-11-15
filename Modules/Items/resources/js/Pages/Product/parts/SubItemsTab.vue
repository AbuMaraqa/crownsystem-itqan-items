<script setup lang="ts">
/** The sub-items of a main item, with the columns of the main item table on orders. */
import { Link } from '@inertiajs/vue3';

import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { SubProductRow } from './types';

defineProps<{ rows: SubProductRow[] }>();
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-muted">
                    <th class="px-2 py-1.5 text-start font-medium">#</th>
                    <th class="px-2 py-1.5 text-start font-medium">{{ t('Item') }}</th>
                    <th class="px-2 py-1.5 text-start font-medium">{{ t('Sku') }}</th>
                    <th class="px-2 py-1.5 text-start font-medium">{{ t('Barcode') }}</th>
                    <th class="px-2 py-1.5 text-end font-medium">{{ t('Length') }}</th>
                    <th class="px-2 py-1.5 text-end font-medium">{{ t('Weight per Meter') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="row.id" class="border-t border-line">
                    <td class="px-2 py-1.5 text-muted">{{ index + 1 }}</td>
                    <td class="px-2 py-1.5">
                        <Link v-if="can('Product Update')" :href="route('products.edit', row.id)" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ row.name }}</Link>
                        <template v-else>{{ row.name }}</template>
                    </td>
                    <td class="px-2 py-1.5 font-mono text-xs" dir="ltr">{{ row.sku }}</td>
                    <td class="px-2 py-1.5 font-mono text-xs" dir="ltr">{{ row.barcode }}</td>
                    <td class="px-2 py-1.5 text-end font-mono tabular-nums">{{ row.length }}</td>
                    <td class="px-2 py-1.5 text-end font-mono tabular-nums">{{ row.weight_per_meter }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
