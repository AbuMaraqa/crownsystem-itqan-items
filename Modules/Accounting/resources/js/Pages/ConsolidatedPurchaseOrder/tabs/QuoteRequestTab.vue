<script setup lang="ts">
import { ref } from 'vue';

import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { can, t } from '@/lib/i18n';
import { ExternalLink } from '@/lib/icons';
import { route } from '@/lib/route';

/** The quote request form sent to a supplier: choose the supplier, then open the PDF. */
const props = defineProps<{ orderId: number }>();

const supplierId = ref<number | string | null>(null);
</script>

<template>
    <Card :title="t('Quote Request Form')">
        <div class="grid max-w-md gap-4">
            <RemoteSelect v-model="supplierId" :url="route('accounting.consolidated-purchase-orders.parties', { role: 'supplier' })" :label="t('Select Supplier')" :placeholder="t('Select a supplier')" />
            <Button
                v-if="can('Consolidated Purchase Order View')"
                variant="primary"
                external
                new-tab
                :href="supplierId ? route('accounting.consolidated-purchase-orders.quote-request', { consolidatedPurchaseOrder: props.orderId, supplier: supplierId }) : '#'"
                :class="{ 'pointer-events-none opacity-50': !supplierId }"
            >
                <ExternalLink />{{ t('View Template') }}
            </Button>
        </div>
    </Card>
</template>
