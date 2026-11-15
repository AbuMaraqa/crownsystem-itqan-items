<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { confirm } from '@/lib/confirm';
import { can, t } from '@/lib/i18n';
import { CircleCheck, Printer, X } from '@/lib/icons';
import { route } from '@/lib/route';

import type { Option } from '../types';
import type { StatusBadgeData, VoucherForm, VoucherFormOptions, VoucherKind } from './types';
import VoucherFields from './VoucherFields.vue';

/** What the page shows around the form (VoucherController::edit `record`). */
export interface VoucherRecord {
    status: StatusBadgeData | null;
    client: Option | null;
    account: string | null;
    currency: string | null;
    payment_method: string | null;
    voucher_date: string | null;
    amount: string;
    editable: boolean;
    can_cancel: boolean;
    cancelled: boolean;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    /** The cheque's status, when the voucher is paid by cheque and posted. */
    cheque: StatusBadgeData | null;
}

const props = defineProps<{
    kind: VoucherKind;
    voucher: VoucherForm & { id: number; reference_no: string };
    record: VoucherRecord;
    options: VoucherFormOptions;
}>();

const { id: voucherId, reference_no: referenceNo, ...values } = props.voucher;
const form = useForm<VoucherForm>(values);
const reason = ref('');

const can_ = (action: string): boolean => can(`${props.kind.entity} ${action}`);
const canCancel = computed(() => props.record.can_cancel && can_('Cancel'));

async function post(): Promise<void> {
    if (await confirm({ title: t(props.kind.postConfirm), confirmLabel: t('Post Voucher'), tone: 'primary' })) {
        form.post(route(`${props.kind.routes}.post`, voucherId), { preserveScroll: true });
    }
}

async function cancel(): Promise<void> {
    if (await confirm({ title: t(props.kind.cancelConfirm), confirmLabel: t('Cancel Voucher') })) {
        router.post(route(`${props.kind.routes}.cancel`, voucherId), { reason: reason.value || null }, { preserveScroll: true, onSuccess: () => (reason.value = '') });
    }
}
</script>

<template>
    <PageHeader :title="`${t(kind.title)} ${referenceNo}`" :parents="[{ title: t(kind.list), href: route(`${kind.routes}.index`) }]">
        <template #actions>
            <Button v-if="record.editable && can_('Post')" variant="primary" :loading="form.processing" @click="post"><CircleCheck />{{ t('Post Voucher') }}</Button>
            <template v-if="canCancel">
                <TextInput v-model="reason" class="w-56" :placeholder="t('Cancellation reason (optional)')" :aria-label="t('Cancellation reason (optional)')" />
                <Button variant="danger" @click="cancel"><X />{{ t('Cancel Voucher') }}</Button>
            </template>
            <Button v-if="can_('View')" external new-tab :href="route(`${kind.routes}.pdf`, voucherId)"><Printer />{{ t('Print') }}</Button>
        </template>
    </PageHeader>

    <div class="space-y-4">
        <Card>
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-muted">{{ t('Status') }}</dt>
                    <dd class="mt-1"><Badge v-if="record.status" :tone="record.status.tone" dot>{{ record.status.label }}</Badge></dd>
                </div>
                <div>
                    <dt class="text-muted">{{ t('Customer / Supplier') }}</dt>
                    <dd class="mt-1 font-medium">{{ record.client?.label ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-muted">{{ t('Voucher Date') }}</dt>
                    <dd class="mt-1 font-medium" dir="ltr">{{ record.voucher_date ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-muted">{{ t('Amount') }}</dt>
                    <dd class="tabular mt-1 font-medium" dir="ltr">{{ record.amount }} {{ record.currency }}</dd>
                </div>
            </dl>
        </Card>

        <div v-if="record.cancelled" class="rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-sm font-medium text-error-700 dark:border-error-500/25 dark:bg-error-500/10 dark:text-error-400">
            {{ t('This document was cancelled on :date.', { date: record.cancelled_at ?? '' }) }}
            <template v-if="record.cancellation_reason"> — {{ record.cancellation_reason }}</template>
        </div>

        <form @submit.prevent="form.put(route(`${kind.routes}.update`, voucherId), { preserveScroll: true })">
            <VoucherFields
                :form="form"
                :options="options"
                :kind="kind"
                :reference-no="referenceNo"
                :status-label="record.status?.label ?? ''"
                :client="record.client"
                :readonly="!record.editable"
            />

            <Card v-if="record.cheque" class="mt-4" :title="t('Cheque Status')">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Badge :tone="record.cheque.tone" dot>{{ record.cheque.label }}</Badge>
                    <Link v-if="can('Cheque View List')" :href="route('accounting.cheques.index')" class="text-sm text-brand-600 hover:underline dark:text-brand-400">{{ t('View in Cheques Register') }}</Link>
                </div>
            </Card>

            <FormActions v-if="record.editable && can_('Update')" :processing="form.processing" :cancel-href="route(`${kind.routes}.index`)" :submit-label="t('Update')" />
        </form>
    </div>
</template>
