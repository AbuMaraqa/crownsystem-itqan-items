<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import SelectInput, { type Option } from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import TreeSelect from '@/Components/form/TreeSelect.vue';
import type { TreeNode } from '@/Components/form/TreeSelectNode.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

export interface AccountForm {
    code: string;
    name: string;
    description: string;
    parent_id: number | string | null;
    type: number | null;
    normal_balance: number | null;
    sort_order: number | string;
    is_postable: boolean;
    is_active: boolean;
}

const props = defineProps<{
    form: InertiaForm<AccountForm>;
    parents: TreeNode[];
    types: Option[];
    normalBalances: Option[];
    /** The normal balance of each account type (assets and expenses debit, the rest credit). */
    normalBalanceByType: Record<string, number>;
    /** The account itself and its children: they cannot be its parent. */
    disabledParents?: number[];
    /** On add only: the postable switch explains itself. */
    postableHint?: boolean;
}>();

// Choosing a type sets its normal balance, which can still be changed after.
watch(
    () => props.form.type,
    (type) => {
        if (type !== null && props.normalBalanceByType[type] !== undefined) {
            props.form.normal_balance = props.normalBalanceByType[type];
        }
    },
);
</script>

<template>
    <div class="grid items-start gap-4 lg:grid-cols-3">
        <Card :title="t('Account Details')" class="lg:col-span-2">
            <div class="grid gap-4 md:grid-cols-2">
                <TextInput v-model="form.code" :label="t('Code')" required dir="ltr" :maxlength="50" :error="form.errors.code" />
                <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
                <TreeSelect
                    v-model="form.parent_id"
                    class="md:col-span-2"
                    :nodes="parents"
                    :label="t('Parent Account')"
                    :placeholder="t('Parent Account')"
                    :disabled-ids="disabledParents"
                    :error="form.errors.parent_id"
                />
                <SelectInput v-model="form.type" :label="t('Type')" required :options="types" :placeholder="t('Select')" :error="form.errors.type" />
                <SelectInput v-model="form.normal_balance" :label="t('Normal Balance')" required :options="normalBalances" :placeholder="t('Select')" :error="form.errors.normal_balance" />
                <TextInput v-model="form.sort_order" type="number" step="1" :label="t('Sort Order')" :error="form.errors.sort_order" />
                <TextareaInput v-model="form.description" class="md:col-span-2" :label="t('Description')" :error="form.errors.description" />
            </div>
        </Card>
        <Card :title="t('Status')">
            <div class="grid gap-4">
                <Toggle v-model="form.is_postable" :label="t('Postable')" :hint="postableHint ? t('Only postable leaf accounts can be used in journal lines.') : undefined" />
                <Toggle v-model="form.is_active" :label="t('Active')" />
            </div>
        </Card>
    </div>
</template>
