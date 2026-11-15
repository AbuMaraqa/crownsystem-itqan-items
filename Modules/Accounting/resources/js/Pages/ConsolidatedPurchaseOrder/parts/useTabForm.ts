import type { FormDataType } from '@inertiajs/core';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

/**
 * The add/edit modal and delete of one tab of the consolidated purchase order page
 * (`accounting.consolidated-purchase-orders.tabs.*`). Files go as multipart on their own (Inertia
 * switches when the form holds a File); an edit is a POST with `_method: put`, so it can carry files.
 */
export function useTabForm<Form extends FormDataType<Form>>(orderId: () => number, tab: string, blank: () => Form) {
    const open = ref(false);
    const editingId = ref<number | null>(null);
    const form = useForm<Form>(blank());

    function show(id: number | null, values: Form): void {
        editingId.value = id;
        form.defaults(values);
        form.reset();
        form.clearErrors();
        open.value = true;
    }

    const add = (values?: Partial<Form>): void => show(null, { ...blank(), ...(values ?? {}) } as Form);
    const edit = (id: number, values: Partial<Form>): void => show(id, { ...blank(), ...values } as Form);

    function submit(): void {
        const options = { preserveScroll: true, preserveState: true, onSuccess: () => (open.value = false) };

        if (editingId.value === null) {
            form.transform((data) => data).post(route('accounting.consolidated-purchase-orders.tabs.store', { consolidatedPurchaseOrder: orderId(), tab }), options);
        } else {
            form.transform((data) => ({ ...data, _method: 'put' })).post(
                route('accounting.consolidated-purchase-orders.tabs.update', { consolidatedPurchaseOrder: orderId(), tab, record: editingId.value }),
                options,
            );
        }
    }

    async function remove(id: number, title: string): Promise<void> {
        if (await confirm({ title, description: t('This cannot be undone.') })) {
            router.delete(route('accounting.consolidated-purchase-orders.tabs.destroy', { consolidatedPurchaseOrder: orderId(), tab, record: id }), { preserveScroll: true, preserveState: true });
        }
    }

    return { open, editingId, form, add, edit, submit, remove };
}
