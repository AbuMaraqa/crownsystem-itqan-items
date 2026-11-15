<script setup lang="ts">
/**
 * Payments and settlements of one document, the Vue side of `Modules\Accounting\Support\PaymentAllocationPanel`.
 *
 *   <PaymentAllocationPanel v-if="allocationPanel" :panel="allocationPanel" />
 *
 * - A voucher or return: what it allocated, the rest to allocate, and the party's open invoices to allocate to.
 * - An invoice: what was paid of it and by which documents (read only).
 * Every action goes back to the page, which sends the panel again.
 */
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { route } from '@/lib/route';

export interface AllocationPanelData {
    is_invoice: boolean;
    can_allocate: boolean;
    source: { type: string; id: number } | null;
    paid: string | null;
    outstanding: string | null;
    allocated: string | null;
    unallocated: string;
    has_unallocated: boolean;
    allocations: { id: number; document: string | null; date: string | null; amount: string; exchange_difference: string | null }[];
    open_invoices: { id: number; reference_no: string; invoice_date: string | null; due_date: string | null; total: string; outstanding: string; suggested: string }[];
}

const props = defineProps<{ panel: AllocationPanelData }>();

const amounts = reactive<Record<number, string>>({});
const busy = ref(false);
const errorFor = ref<{ invoiceId: number; message: string } | null>(null);

const options = (invoiceId?: number) => ({
    preserveScroll: true,
    onStart: () => (busy.value = true),
    onFinish: () => (busy.value = false),
    onSuccess: () => {
        for (const key of Object.keys(amounts)) delete amounts[Number(key)];
        errorFor.value = null;
    },
    onError: (errors: Record<string, string>) => {
        const message = errors.amount ?? errors.invoice_id;
        errorFor.value = invoiceId && message ? { invoiceId, message } : null;
    },
});

function sourceParams(): { sourceType: string; source: number } {
    return { sourceType: props.panel.source!.type, source: props.panel.source!.id };
}

function allocate(invoiceId: number): void {
    router.post(route('accounting.payment-allocations.allocate', sourceParams()), { invoice_id: invoiceId, amount: amounts[invoiceId] || null }, options(invoiceId));
}

function allocateAutomatically(): void {
    router.post(route('accounting.payment-allocations.auto', sourceParams()), {}, options());
}

async function deallocate(id: number): Promise<void> {
    if (await confirm({ title: t('Remove this allocation?'), confirmLabel: t('Remove') })) {
        router.delete(route('accounting.payment-allocations.destroy', id), options());
    }
}

const cell = 'px-4 py-2.5';
</script>

<template>
    <Card :title="panel.is_invoice ? t('Payments and Settlements') : t('Allocation to Invoices')">
        <template #actions>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <template v-if="panel.is_invoice">
                    <span class="text-muted">{{ t('Paid') }}: <strong class="text-ink tabular-nums" dir="ltr">{{ panel.paid }}</strong></span>
                    <span class="text-muted">{{ t('Outstanding') }}: <strong class="text-error-600 tabular-nums" dir="ltr">{{ panel.outstanding }}</strong></span>
                </template>
                <template v-else>
                    <span class="text-muted">{{ t('Allocated') }}: <strong class="text-ink tabular-nums" dir="ltr">{{ panel.allocated }}</strong></span>
                    <span class="text-muted">{{ t('Unallocated') }}: <strong class="text-brand-600 tabular-nums dark:text-brand-400" dir="ltr">{{ panel.unallocated }}</strong></span>
                    <Button
                        v-if="panel.can_allocate && panel.has_unallocated && panel.open_invoices.length && can('Payment Allocation Create')"
                        size="sm"
                        variant="primary"
                        :loading="busy"
                        @click="allocateAutomatically"
                    >
                        {{ t('Allocate Automatically (Oldest First)') }}
                    </Button>
                </template>
            </div>
        </template>

        <div class="-m-5 overflow-x-auto">
            <table class="w-full min-w-[36rem] text-sm">
                <thead class="bg-gray-50/80 text-[13px] text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                    <tr class="border-b border-line">
                        <th :class="[cell, 'text-start font-medium']">{{ panel.is_invoice ? t('Document') : t('Invoice') }}</th>
                        <th :class="[cell, 'text-start font-medium']">{{ t('Date') }}</th>
                        <th :class="[cell, 'text-end font-medium']">{{ t('Amount') }}</th>
                        <th :class="[cell, 'text-end font-medium']">{{ t('Exchange Difference') }}</th>
                        <th v-if="!panel.is_invoice" :class="cell"><span class="sr-only">{{ t('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="allocation in panel.allocations" :key="allocation.id" class="border-b border-line last:border-b-0">
                        <td :class="cell"><bdi>{{ allocation.document ?? '-' }}</bdi></td>
                        <td :class="cell">{{ allocation.date }}</td>
                        <td :class="[cell, 'text-end tabular-nums']" dir="ltr">{{ allocation.amount }}</td>
                        <td :class="[cell, 'text-end tabular-nums']" dir="ltr">{{ allocation.exchange_difference ?? '-' }}</td>
                        <td v-if="!panel.is_invoice" :class="[cell, 'text-end']">
                            <button v-if="can('Payment Allocation Delete')" type="button" class="text-xs font-semibold text-error-600 hover:underline disabled:opacity-60" :disabled="busy" @click="deallocate(allocation.id)">
                                {{ t('Remove') }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!panel.allocations.length">
                        <td :colspan="panel.is_invoice ? 4 : 5" :class="[cell, 'text-center text-muted']">{{ t('No allocations yet.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template v-if="panel.can_allocate && panel.has_unallocated">
            <h4 class="mt-8 mb-2 text-sm font-semibold">{{ t('Open Invoices') }}</h4>
            <div class="-mx-5 -mb-5 overflow-x-auto border-t border-line">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="bg-gray-50/80 text-[13px] text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                        <tr class="border-b border-line">
                            <th :class="[cell, 'text-start font-medium']">{{ t('Invoice') }}</th>
                            <th :class="[cell, 'text-start font-medium']">{{ t('Date') }}</th>
                            <th :class="[cell, 'text-start font-medium']">{{ t('Due Date') }}</th>
                            <th :class="[cell, 'text-end font-medium']">{{ t('Total') }}</th>
                            <th :class="[cell, 'text-end font-medium']">{{ t('Outstanding') }}</th>
                            <th :class="[cell, 'text-end font-medium']">{{ t('Amount to Allocate') }}</th>
                            <th :class="cell"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in panel.open_invoices" :key="invoice.id" class="border-b border-line last:border-b-0">
                            <td :class="cell"><bdi>{{ invoice.reference_no }}</bdi></td>
                            <td :class="cell">{{ invoice.invoice_date }}</td>
                            <td :class="cell">{{ invoice.due_date ?? '-' }}</td>
                            <td :class="[cell, 'text-end tabular-nums']" dir="ltr">{{ invoice.total }}</td>
                            <td :class="[cell, 'text-end tabular-nums']" dir="ltr">{{ invoice.outstanding }}</td>
                            <td :class="[cell, 'text-end']">
                                <input
                                    v-model="amounts[invoice.id]"
                                    type="text"
                                    inputmode="decimal"
                                    dir="ltr"
                                    :placeholder="invoice.suggested"
                                    :aria-label="`${t('Amount to Allocate')} - ${invoice.reference_no}`"
                                    :aria-invalid="errorFor?.invoiceId === invoice.id"
                                    class="h-9 w-32 rounded-control border bg-card px-2 text-end text-sm outline-none focus:border-brand"
                                    :class="errorFor?.invoiceId === invoice.id ? 'border-danger' : 'border-line'"
                                />
                                <p v-if="errorFor?.invoiceId === invoice.id" class="mt-1 text-xs text-danger" role="alert">{{ errorFor.message }}</p>
                            </td>
                            <td :class="[cell, 'text-end']">
                                <button v-if="can('Payment Allocation Create')" type="button" class="text-xs font-semibold text-brand-600 hover:underline disabled:opacity-60 dark:text-brand-400" :disabled="busy" @click="allocate(invoice.id)">
                                    {{ t('Allocate') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!panel.open_invoices.length">
                            <td colspan="7" :class="[cell, 'text-center text-muted']">{{ t('No open invoices for this party in the same currency.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </Card>
</template>
