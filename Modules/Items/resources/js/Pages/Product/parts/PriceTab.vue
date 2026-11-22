<script setup lang="ts">
/**
 * The price of a non-weight item or of one of its variation rows: its base price only. The cost is not
 * typed here, it comes from the weighted average of the purchases. A weight item shows KgPriceTable.
 */
import TextInput from '@/Components/form/TextInput.vue';
import { t } from '@/lib/i18n';

import type { ErrorOf, ProductRowFields } from './types';

defineProps<{ row: ProductRowFields; error: ErrorOf }>();
</script>

<template>
    <div class="grid gap-6 md:grid-cols-2">
        <TextInput v-model="row.base_price" :label="t('Base Price')" type="number" step="any" min="0" required :error="error('base_price')" />
    </div>
</template>
