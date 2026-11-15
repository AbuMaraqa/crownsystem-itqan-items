import { router, type InertiaForm } from '@inertiajs/vue3';

import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

interface Texts {
    /** Asked before posting (e.g. "Post this receipt? The quantities will be added…"). */
    postQuestion: string;
    postLabel: string;
    cancelQuestion: string;
    cancelLabel: string;
}

/**
 * The actions of an inventory document page, the same for the four documents: save the draft,
 * post (saving the screen first), cancel a posted document, delete a draft. `prefix` is the
 * route group: `goods-receipts`, `delivery-notes`, `transfers`, `adjustments`.
 */
export function useDocumentActions<T extends object>(prefix: string, form: InertiaForm<T>, id: () => number | null, texts: Texts) {
    const name = (action: string): string => `accounting.inventory.${prefix}.${action}`;
    const options = { preserveScroll: true };

    function save(): void {
        const recordId = id();

        if (recordId) form.put(route(name('update'), recordId), options);
        else form.post(route(name('store')), options);
    }

    async function post(): Promise<void> {
        if (!(await confirm({ title: texts.postQuestion, confirmLabel: texts.postLabel, tone: 'primary' }))) return;

        const recordId = id();

        if (recordId) form.post(route(name('post'), recordId), options);
        else form.transform((data) => ({ ...data, post: true })).post(route(name('store')), { ...options, onFinish: () => form.transform((data) => data) });
    }

    async function cancel(): Promise<void> {
        const recordId = id();

        if (recordId && (await confirm({ title: texts.cancelQuestion, confirmLabel: texts.cancelLabel }))) {
            router.post(route(name('cancel'), recordId), {}, options);
        }
    }

    async function remove(): Promise<void> {
        const recordId = id();

        if (recordId && (await confirm({ title: t('Delete this draft?'), confirmLabel: t('Delete') }))) {
            router.delete(route(name('destroy'), recordId));
        }
    }

    return { save, post, cancel, remove };
}
