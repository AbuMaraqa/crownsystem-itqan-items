<script setup lang="ts">
/**
 * The three stock limits of an item or a variation (in the stock unit). They are checked together
 * on the server (StockLimits), and only a holder of "Stock Limit Update" changes them.
 */
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';

import type { ErrorOf, ProductRowFields } from './types';

defineProps<{ row: ProductRowFields; error: ErrorOf; editable: boolean }>();
</script>

<template>
    <TextInput v-model="row.max_limit" :label="t('Max Limit')" type="number" step="any" min="0" :disabled="!editable" :hint="t('Enough for about 6 months; the target when ordering.')" :error="error('max_limit')" />
    <TextInput v-model="row.min_limit_1" :label="t('Min Limit 1')" type="number" step="any" min="0" :disabled="!editable" :hint="t('Enough for about 3 months; the item enters stock follow-up with a warning.')" :error="error('min_limit_1')" />
    <TextInput v-model="row.min_limit_2" :label="t('Min Limit 2')" type="number" step="any" min="0" :disabled="!editable" :hint="t('Enough for about one month; the item must be ordered.')" :error="error('min_limit_2')" />
</template>
