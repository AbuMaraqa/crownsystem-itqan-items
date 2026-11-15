<script setup lang="ts">
import { t } from '@/lib/i18n';

import type { Suggestion } from './types';

/** One order suggestion: the quantity to order, «No order needed» or «No withdrawal data». */
defineProps<{ suggestion: Suggestion | null }>();
</script>

<template>
    <span v-if="!suggestion || suggestion.state === 'none_shown'" class="text-gray-400">—</span>
    <span v-else-if="suggestion.state === 'order'" class="font-semibold text-gray-900 tabular-nums dark:text-white" dir="ltr">{{ suggestion.quantity }}</span>
    <span v-else-if="suggestion.state === 'no_data'" class="text-xs text-muted">{{ t('No withdrawal data') }}</span>
    <span v-else class="text-xs text-success-700 dark:text-success-400">{{ t('No order needed') }}</span>
</template>
