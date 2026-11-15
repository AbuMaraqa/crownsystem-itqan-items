import { router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';

type Value = string | number | boolean | null | undefined;

interface Options<T> {
    /** Text filters: the page reloads after typing stops. */
    debounce?: (keyof T)[];
    /**
     * Filters sent even when empty: an empty date means "no limit", while a missing one
     * means the report's default (e.g. the start of the fiscal year).
     */
    keepEmpty?: (keyof T)[];
}

/**
 * A report's filters live in the URL (GET), so a report can be bookmarked and shared.
 * Every change reloads the page with the new filters, keeping the page state and scroll.
 */
export function useReportFilters<T extends Record<string, Value>>(initial: T, options: Options<T> = {}) {
    const filters = reactive({ ...initial }) as T;
    const loading = ref(false);
    const debounced = new Set<keyof T>(options.debounce ?? []);
    const keepEmpty = new Set<keyof T>(options.keepEmpty ?? []);

    /** The query string of the current filters (and `extra`), for a reload or an export link. */
    function query(extra: Record<string, Value> = {}): Record<string, string | number> {
        const params: Record<string, string | number> = {};

        for (const [key, value] of Object.entries({ ...filters, ...extra })) {
            if (value === undefined) continue;
            if ((value === null || value === '') && !keepEmpty.has(key as keyof T)) continue;

            params[key] = typeof value === 'boolean' ? (value ? 1 : 0) : (value ?? '');
        }

        return params;
    }

    function reload(extra: Record<string, Value> = {}): void {
        router.get(window.location.pathname, query(extra), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        });
    }

    let timer: ReturnType<typeof setTimeout> | undefined;

    for (const key of Object.keys(initial) as (keyof T)[]) {
        watch(
            () => filters[key],
            () => {
                clearTimeout(timer);

                if (debounced.has(key)) timer = setTimeout(() => reload(), 300);
                else reload();
            },
        );
    }

    return { filters, loading, query, reload };
}
