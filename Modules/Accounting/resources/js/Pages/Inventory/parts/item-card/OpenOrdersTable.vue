<script setup lang="ts">
import { computed } from 'vue';

import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';

import { tableClasses as c } from '../../../Report/parts/types';
import type { OpenOrder } from './types';

/**
 * Open orders of one item: sales orders (reserved) or purchase orders (incoming, with the expected
 * delivery date). The reference opens the order for who may open it, in a new tab when `newTab`
 * (so a document being filled is not left). Shared by the item stock card and the items grid.
 */
const props = defineProps<{ rows: OpenOrder[]; type: 'sales' | 'purchase'; newTab?: boolean }>();

const link = computed(() =>
    props.type === 'sales'
        ? { route: 'accounting.sales-orders.edit', allowed: can('Sales Order Update') }
        : { route: 'accounting.purchase-orders.edit', allowed: can('Purchase Order View List') },
);
</script>

<template>
    <table class="w-full text-sm">
        <tbody>
            <tr v-for="row in rows" :key="row.order_id" class="border-b border-line last:border-0">
                <td class="py-2">
                    <a v-if="link.allowed" :href="route(link.route, row.order_id)" :target="newTab ? '_blank' : undefined" :class="[c.link, 'font-mono font-semibold']" dir="ltr">{{ row.reference }}</a>
                    <bdi v-else class="font-mono font-semibold" dir="ltr">{{ row.reference }}</bdi>
                </td>
                <td class="py-2 text-gray-600 dark:text-gray-300">{{ row.party }}</td>
                <td class="py-2 text-xs text-muted" :title="row.expected ? t('Expected Delivery Date') : t('Date')" dir="ltr">{{ row.expected ?? row.date }}</td>
                <td class="py-2 text-end font-semibold tabular-nums" dir="ltr">{{ row.quantity }}</td>
            </tr>
            <tr v-if="!rows.length"><td colspan="4" class="py-6 text-center text-muted">{{ t('None') }}</td></tr>
        </tbody>
    </table>
</template>
