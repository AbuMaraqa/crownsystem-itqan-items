<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import TextareaInput from '@/Components/form/TextareaInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { confirm } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { CircleCheck, X } from '@/lib/icons';
import { route } from '@/lib/route';

import PartyCell from '../parts/PartyCell.vue';
import TabTable from '../parts/TabTable.vue';
import type { TabRecord } from '../types';

/** Award the order to one price offer (cheapest first); one award at a time, which can be cancelled. */
const props = defineProps<{ orderId: number; rows: TabRecord[] }>();

const sorted = computed(() => [...props.rows].sort((a, b) => Number(a.total_value) - Number(b.total_value)));
const anyAwarded = computed(() => props.rows.some((row) => row.awarded));

const awarding = ref<TabRecord | null>(null);
const awardOpen = ref(false);
const awardForm = useForm<{ notes: string | null }>({ notes: null });

function award(row: TabRecord): void {
    awarding.value = row;
    awardForm.reset();
    awardOpen.value = true;
}

function confirmAward(): void {
    if (!awarding.value) return;

    awardForm.post(route('accounting.consolidated-purchase-orders.award', { consolidatedPurchaseOrder: props.orderId, offer: awarding.value.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (awardOpen.value = false),
    });
}

async function cancelAward(): Promise<void> {
    if (await confirm({ title: t('Are you sure you want to cancel this award?') })) {
        router.delete(route('accounting.consolidated-purchase-orders.award.cancel', props.orderId), { preserveScroll: true, preserveState: true });
    }
}
</script>

<template>
    <TabTable
        :title="t('Awarding')"
        :rows="sorted"
        :columns="[
            { key: 'supplier', label: t('Supplier') },
            { key: 'offer_date', label: t('Offer Date') },
            { key: 'currency', label: t('Currency') },
            { key: 'total_amount', label: t('Total'), class: 'text-end' },
            { key: 'awarded', label: t('Awarded') },
        ]"
        :empty-text="t('No price offers have been added yet. Add price offers before awarding.')"
    >
        <template #cell-supplier="{ row }"><PartyCell :user="row.supplier" /></template>
        <template #cell-offer_date="{ row }"><span dir="ltr">{{ row.offer_date }}</span></template>
        <template #cell-total_amount="{ row }"><span class="font-mono tabular-nums" dir="ltr">{{ row.total_amount }}</span></template>
        <template #cell-awarded="{ row }"><CircleCheck v-if="row.awarded" class="size-5 text-success" :aria-label="t('Awarded')" /><span v-else class="text-muted">—</span></template>
        <template #actions="{ row }">
            <Button v-if="!anyAwarded" size="sm" variant="light" @click="award(row)"><CircleCheck />{{ t('Award') }}</Button>
            <Button v-else-if="row.awarded" size="sm" variant="danger" @click="cancelAward"><X />{{ t('Cancel Award') }}</Button>
        </template>
    </TabTable>

    <Modal v-model:open="awardOpen" :title="t('Are you sure you want to award the order to the selected supplier?')" width="max-w-md">
        <form class="grid gap-4" @submit.prevent="confirmAward">
            <TextareaInput v-model="awardForm.notes" :label="t('Award Notes')" :rows="2" :error="awardForm.errors.notes" />
        </form>
        <template #footer>
            <Button @click="awardOpen = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="awardForm.processing" @click="confirmAward">{{ t('Award') }}</Button>
        </template>
    </Modal>
</template>
