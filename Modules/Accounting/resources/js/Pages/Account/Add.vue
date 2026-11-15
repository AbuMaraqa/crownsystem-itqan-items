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
    /** The new account's values; under a parent: the next code in its branch, its type and normal balance. */
    account: Omit<AccountForm, 'name' | 'description'>;
    parents: TreeNode[];
    types: Option[];
    normalBalances: Option[];
    normalBalanceByType: Record<string, number>;
}>();

const form = useForm<AccountForm>({ name: '', description: '', ...props.account });
</script>

<template>
    <PageHeader :title="t('Add Account')" :parents="[{ title: t('Chart of Accounts'), href: route('accounting.accounts.index') }]" />
    <form @submit.prevent="form.post(route('accounting.accounts.store'))">
        <AccountFields :form="form" :parents="parents" :types="types" :normal-balances="normalBalances" :normal-balance-by-type="normalBalanceByType" postable-hint />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.accounts.index')" :submit-label="t('Add Account')" />
    </form>
</template>
