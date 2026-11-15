<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { can, t } from '@/lib/i18n';
import { CircleCheck, Pencil, X } from '@/lib/icons';
import { route } from '@/lib/route';

import type { StatusBadge } from '../parts/statusBadge';
import { useJournalEntryActions } from './parts/useJournalEntryActions';

defineOptions({ layout: AdminLayout });

interface Line {
    id: number;
    account: string;
    branch: string | null;
    currency: string | null;
    exchange_rate: string;
    debit: string;
    credit: string;
    debit_base: string;
    credit_base: string;
    description: string | null;
}

const props = defineProps<{
    entry: {
        id: number;
        entry_number: string;
        entry_date: string | null;
        status: StatusBadge | null;
        reference_no: string | null;
        description: string | null;
        reversal_of: { id: number; entry_number: string } | null;
        created_by: string | null;
        posted_by: string | null;
        posted_at: string | null;
        is_draft: boolean;
        can_void: boolean;
        debit_total: string;
        credit_total: string;
        lines: Line[];
    };
}>();

const { post, voidEntry } = useJournalEntryActions();

const details = [
    { label: t('Entry Date'), value: props.entry.entry_date },
    { label: t('Reference No'), value: props.entry.reference_no },
    { label: t('Created By'), value: props.entry.created_by },
    { label: t('Posted By'), value: props.entry.posted_by },
    { label: t('Posted At'), value: props.entry.posted_at },
];
</script>

<template>
    <PageHeader :title="entry.entry_number" :parents="[{ title: t('Journal Entries'), href: route('accounting.journal-entries.index') }]">
        <template #actions>
            <Badge v-if="entry.status" :tone="entry.status.tone" dot>{{ entry.status.label }}</Badge>
            <Button v-if="entry.is_draft && can('Journal Entry Update')" :href="route('accounting.journal-entries.edit', entry.id)"><Pencil />{{ t('Edit') }}</Button>
            <Button v-if="entry.is_draft && can('Journal Entry Post')" variant="primary" @click="post(entry.id, entry.entry_number)"><CircleCheck />{{ t('Post') }}</Button>
            <Button v-if="entry.can_void && can('Journal Entry Void')" variant="danger" @click="voidEntry(entry.id, entry.entry_number)"><X />{{ t('Void') }}</Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <Card :title="t('Journal Entry Details')">
            <dl class="grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="field in details" :key="field.label" class="min-w-0">
                    <dt class="text-muted">{{ field.label }}</dt>
                    <dd class="mt-0.5 break-words">{{ field.value || '-' }}</dd>
                </div>
                <div v-if="entry.reversal_of" class="min-w-0">
                    <dt class="text-muted">{{ t('Reversal for') }}</dt>
                    <dd class="mt-0.5">
                        <Link :href="route('accounting.journal-entries.show', entry.reversal_of.id)" class="text-brand-600 hover:underline dark:text-brand-400" dir="ltr">{{ entry.reversal_of.entry_number }}</Link>
                    </dd>
                </div>
                <div v-if="entry.description" class="min-w-0 sm:col-span-3 lg:col-span-5">
                    <dt class="text-muted">{{ t('Description') }}</dt>
                    <dd class="mt-0.5 whitespace-pre-line">{{ entry.description }}</dd>
                </div>
            </dl>
        </Card>

        <Card :title="t('Journal Lines')">
            <div class="-m-5 overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-gray-50/80 text-[13px] text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                        <tr class="border-b border-line">
                            <th class="px-4 py-2.5 text-start font-medium">#</th>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('Account') }}</th>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('Branch') }}</th>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('Currency') }}</th>
                            <th class="px-4 py-2.5 text-end font-medium">{{ t('Exchange Rate') }}</th>
                            <th class="px-4 py-2.5 text-end font-medium">{{ t('Debit') }}</th>
                            <th class="px-4 py-2.5 text-end font-medium">{{ t('Credit') }}</th>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in entry.lines" :key="line.id" class="border-b border-line last:border-b-0">
                            <td class="px-4 py-2.5 text-muted tabular-nums">{{ index + 1 }}</td>
                            <td class="px-4 py-2.5">{{ line.account }}</td>
                            <td class="px-4 py-2.5">{{ line.branch || '-' }}</td>
                            <td class="px-4 py-2.5">{{ line.currency }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ line.exchange_rate }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ line.debit }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ line.credit }}</td>
                            <td class="px-4 py-2.5">{{ line.description || '-' }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-line bg-gray-50/80 font-semibold dark:bg-white/[0.02]">
                            <td colspan="5" class="px-4 py-2.5 text-end">{{ t('Debit Total') }} / {{ t('Credit Total') }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ entry.debit_total }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums" dir="ltr">{{ entry.credit_total }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    </div>
</template>
