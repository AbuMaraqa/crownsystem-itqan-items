<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import type { TreeNode } from '@/Components/form/TreeSelectNode.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import AccountFields, { type AccountForm } from './AccountFields.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    account: AccountForm & { id: number };
    disabledParents: number[];
    parents: TreeNode[];
    types: Option[];
    normalBalances: Option[];
    normalBalanceByType: Record<string, number>;
}>();

const { id: accountId, ...values } = props.account;
const form = useForm<AccountForm>(values);
</script>

<template>
    <PageHeader :title="t('Edit Account')" :parents="[{ title: t('Chart of Accounts'), href: route('accounting.accounts.index') }]" />
    <form @submit.prevent="form.put(route('accounting.accounts.update', accountId))">
        <AccountFields
            :form="form"
            :parents="parents"
            :types="types"
            :normal-balances="normalBalances"
            :normal-balance-by-type="normalBalanceByType"
            :disabled-parents="disabledParents"
        />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.accounts.index')" :submit-label="t('Update')" />
    </form>
</template>
