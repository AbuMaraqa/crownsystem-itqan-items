<script setup lang="ts">
/**
 * The inventory tab (perpetual inventory): the item card sets planning data only, the colour and the
 * three limits. Quantity and cost come from inventory documents, summarised below for reading.
 */
import type { InertiaForm } from '@inertiajs/vue3';
import { useId } from 'vue';

import Field from '@/Components/form/Field.vue';
import { controlClasses } from '@/Components/form/controlClasses';
import { t } from '@/lib/i18n';

import StockLimitFields from './StockLimitFields.vue';
import StockSummaryPanel from './StockSummaryPanel.vue';
import type { ErrorOf, ProductContext, ProductForm, ProductFormOptions } from './types';

defineProps<{ form: InertiaForm<ProductForm>; options: ProductFormOptions; context: ProductContext; error: ErrorOf }>();

const colorId = useId();
const colorsId = useId();
</script>

<template>
    <div class="grid gap-6">
        <section>
            <h3 class="text-sm font-semibold">{{ t('Stock Limits') }}</h3>
            <p class="mb-4 text-sm text-muted">{{ t('In the stock unit (pieces for aluminum profiles). Max > Min 1 > Min 2; all three zero = the item is not monitored.') }}</p>
            <div class="grid gap-4 md:grid-cols-4">
                <!-- Colour names are suggested so they stay written one way (Product::colorOptions). -->
                <Field :id="colorId" :label="t('Color')" :error="error('color')" :hint="t('Write it on the aluminum type; its sub-items take it from there.')">
                    <input :id="colorId" v-model="form.color" :list="colorsId" maxlength="50" :aria-invalid="!!error('color')" :class="[controlClasses, 'h-control']" />
                    <datalist :id="colorsId">
                        <option v-for="color in options.colors" :key="color" :value="color" />
                    </datalist>
                </Field>
                <StockLimitFields :row="form" :error="error" :editable="options.canEditLimits" />
            </div>
        </section>

        <StockSummaryPanel :stock="context.stock" :links="context.stockLinks" />
    </div>
</template>
