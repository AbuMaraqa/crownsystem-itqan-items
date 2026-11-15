<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';

import { paymentVoucher, type VoucherForm, type VoucherFormOptions } from '../parts/voucher/types';
import VoucherEdit, { type VoucherRecord } from '../parts/voucher/VoucherEdit.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<VoucherFormOptions & { voucher: VoucherForm & { id: number; reference_no: string }; record: VoucherRecord }>();
</script>

<template>
    <VoucherEdit :kind="paymentVoucher" :voucher="voucher" :record="record" :options="props" />
</template>
