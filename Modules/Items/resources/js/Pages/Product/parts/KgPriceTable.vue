<script setup lang="ts">
/**
 * The price tab of a weight item: the kg sell price of each of its colours in every price list, to read
 * only. The price belongs to the price group (category × colour), so it is changed on the category page
 * and every item of that category and colour follows it.
 */
import { computed } from 'vue';

import Alert from '@/Components/ui/Alert.vue';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { ColourCatalogue, VariationRow } from './types';
import { kgPrice } from './useCategoryColours';

const props = defineProps<{ catalogue: ColourCatalogue; rows: VariationRow[]; categoryIds: (number | string)[] }>();

const chosen = computed(() => props.catalogue.colours.filter((colour) => props.rows.some((row) => row.category_finish_id === colour.id)));
const categoryUrl = computed(() => (props.categoryIds.length && can('Category Update') ? route('categories.edit', props.categoryIds[0]) : null));
</script>

<template>
    <div class="grid gap-4">
        <Alert>
            {{ t('A weight item is priced per kg: weight per meter × length × the kg price of its colour. Kg prices are set on the category page.') }}
            <a v-if="categoryUrl" :href="categoryUrl" class="ms-1 font-medium underline">{{ t('Open the category') }}</a>
        </Alert>

        <p v-if="!chosen.length" class="text-sm text-muted">{{ t("Choose the item's colours to see their kg prices.") }}</p>

        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-muted">
                        <th class="px-2 py-1.5 text-start font-medium">{{ t('Colour') }}</th>
                        <th v-for="list in catalogue.lists" :key="list.id" class="px-2 py-1.5 text-end font-medium">{{ list.name }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="colour in chosen" :key="colour.id" class="border-t border-line">
                        <td class="px-2 py-1.5 font-medium">
                            <span v-if="colour.swatch" class="me-1.5 inline-block size-3 rounded-full border border-line align-middle" :style="{ backgroundColor: colour.swatch }" />
                            {{ colour.name }}
                        </td>
                        <td v-for="list in catalogue.lists" :key="list.id" class="px-2 py-1.5 text-end font-mono tabular-nums">
                            {{ kgPrice(colour.prices, list.id) ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
