<script setup lang="ts">
/**
 * The price of an item or of one variation row: its base price only. The cost is not typed here, it
 * comes from the weighted average of the purchases. A weight item has no price on its card (the price
 * per kg comes from the price lists); a piece has none either, its amount is worked out on its main item.
 */
import { computed } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';

import type { ErrorOf, ProductRowFields } from './types';

const props = defineProps<{ row: ProductRowFields; error: ErrorOf; weight?: boolean; piece?: boolean }>();

const note = computed(() =>
    props.piece
        ? t('A piece has no price. The amount is calculated on its main item.')
        : t('This item groups its pieces. The price per kg comes from the price lists and can be changed on the document line.'),
);
</script>

<template>
    <div class="grid gap-6 md:grid-cols-2">
        <p v-if="weight" class="rounded-lg border border-blue-light-200 bg-blue-light-50 p-3 text-sm text-blue-light-700 md:col-span-2 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-400">
            {{ note }}
        </p>
        <TextInput v-else v-model="row.base_price" :label="t('Base Price')" type="number" step="any" min="0" required :error="error('base_price')" />
    </div>
</template>
