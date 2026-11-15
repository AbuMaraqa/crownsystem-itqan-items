<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';

import { paymentVoucher, type VoucherForm, type VoucherFormOptions } from '../parts/voucher/types';
import VoucherAdd from '../parts/voucher/VoucherAdd.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<VoucherFormOptions & { voucher: VoucherForm & { reference_no: string } }>();
</script>

<template>
    <VoucherAdd :kind="paymentVoucher" :voucher="voucher" :options="props" />
</template>
