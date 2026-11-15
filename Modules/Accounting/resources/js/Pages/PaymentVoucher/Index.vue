<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import type { TablePayload } from '@/types/table';

import type { Option } from '../parts/types';
import { paymentVoucher } from '../parts/voucher/types';
import VoucherIndex, { type VoucherRow } from '../parts/voucher/VoucherIndex.vue';

defineOptions({ layout: AdminLayout });

defineProps<{ table: TablePayload<VoucherRow>; statusOptions: Option[]; currencyOptions: Option[]; paymentMethods: Option[] }>();
</script>

<template>
    <VoucherIndex :kind="paymentVoucher" :table="table" :status-options="statusOptions" :currency-options="currencyOptions" :payment-methods="paymentMethods" />
</template>
