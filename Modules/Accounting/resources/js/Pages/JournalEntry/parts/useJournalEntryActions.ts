import { router } from '@inertiajs/vue3';

import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

/**
 * Post, void and delete one journal entry after a confirmation; shared by the list and the entry page.
 */
export function useJournalEntryActions() {
    async function post(id: number, number: string): Promise<void> {
        if (await confirm({ title: t('Post'), description: number, tone: 'primary', confirmLabel: t('Post') })) {
            router.post(route('accounting.journal-entries.post', id), {}, { preserveScroll: true });
        }
    }

    async function voidEntry(id: number, number: string): Promise<void> {
        if (await confirm({ title: t('Void'), description: number, confirmLabel: t('Void') })) {
            router.post(route('accounting.journal-entries.void', id), {}, { preserveScroll: true });
        }
    }

    async function remove(id: number, number: string): Promise<void> {
        if (await confirm({ title: t('Delete :name?', { name: number }), description: t('This cannot be undone.') })) {
            router.delete(route('accounting.journal-entries.destroy', id), { preserveScroll: true });
        }
    }

    return { post, voidEntry, remove };
}
