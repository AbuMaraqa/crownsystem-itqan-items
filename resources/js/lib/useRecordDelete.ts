import { router } from '@inertiajs/vue3';

import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

/**
 * Delete one row of a table, after a confirmation. `routes` is the route names' prefix: `tags` in
 * `tags.destroy`. Selected rows are deleted by `DataTable :bulk-delete-url` itself.
 */
export function useRecordDelete(routes: string) {
    async function remove(id: number, name: string): Promise<void> {
        if (await confirm({ title: t('Delete :name?', { name }), description: t('This cannot be undone.') })) {
            router.delete(route(`${routes}.destroy`, id), { preserveScroll: true });
        }
    }

    return { remove };
}
