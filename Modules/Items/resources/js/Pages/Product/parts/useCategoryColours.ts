import type { InertiaForm } from '@inertiajs/vue3';
import { HttpError, getJson } from '@modules/Accounting/resources/js/Pages/parts/http';
import { onBeforeUnmount, ref, watch } from 'vue';

import { route } from '@/lib/route';

import { isWeight } from './productForm';
import type { ColourCatalogue, ProductForm } from './types';

/**
 * The colours a weight item chooses from: the price groups of its categories with their kg prices
 * (`products.colours`, CategoryColours on the server). Loaded again when the categories change; the
 * price groups its saved colours use are asked for too, so they show even from another category.
 */
export function useCategoryColours(form: InertiaForm<ProductForm>) {
    const catalogue = ref<ColourCatalogue>({ lists: [], colours: [] });
    const loading = ref(false);
    const failed = ref<string | null>(null);
    let controller: AbortController | null = null;

    async function load(): Promise<void> {
        controller?.abort();

        if (!isWeight(form)) return;

        controller = new AbortController();
        loading.value = true;
        failed.value = null;

        try {
            catalogue.value = await getJson<ColourCatalogue>(
                route('products.colours', {
                    categories: form.category_id,
                    include: form.variations.map((row) => row.category_finish_id).filter((id) => id !== null),
                }),
                controller.signal,
            );
        } catch (error) {
            if ((error as Error).name === 'AbortError') return;

            failed.value = error instanceof HttpError ? error.firstMessage() : String(error);
        } finally {
            loading.value = false;
        }
    }

    watch(() => [isWeight(form), form.category_id.map(String).join(',')], load, { immediate: true });
    onBeforeUnmount(() => controller?.abort());

    return { catalogue, loading, failed, reload: load };
}

/** A colour's kg sell price in a price list, or null. An empty PHP map arrives as a list. */
export function kgPrice(prices: Record<string, string | null> | never[], listId: number): string | null {
    return Array.isArray(prices) ? null : (prices[String(listId)] ?? null);
}
