import { router } from '@inertiajs/vue3';

import { route } from '@/lib/route';

/**
 * The Active switch inside a table row: saves at once through `<routes>.active`
 * (`accounting.taxes` in `accounting.taxes.active`), the server answers with a toast.
 */
export function useActiveToggle(routes: string) {
    return (id: number, active: boolean): void => {
        router.put(route(`${routes}.active`, id), { is_active: active }, { preserveScroll: true });
    };
}
