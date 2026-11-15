<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import SelectInput, { type Option } from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

export interface UserForm {
    name: string;
    email: string;
    phone: string | null;
    password: string;
    password_confirmation: string;
    roles: number[];
    branch_id: number | null;
    customer_group_id: number | null;
    profit_margin: string | number | null;
    /** 1, 0, or null to follow the customer group. */
    use_last_price: number | null;
    price_list_id: number | null;
}

/** Form choices from UserController::formOptions. */
export interface UserFormOptions {
    roleOptions: Option[];
    /** Null while the Branch module is off. */
    branchOptions: Option[] | null;
    customerGroupOptions: Option[];
    priceListOptions: Option[];
    customerRoleIds: number[];
    partyRoleIds: number[];
}

const props = defineProps<{ form: InertiaForm<UserForm>; options: UserFormOptions; editing?: boolean }>();

/** A failure on one chosen role arrives as `roles.0`. */
const rolesError = computed(() => {
    const errors = props.form.errors as Record<string, string | undefined>;
    return errors.roles ?? Object.entries(errors).find(([key]) => key.startsWith('roles.'))?.[1];
});

const hasRole = (ids: number[]) => props.form.roles.some((role) => ids.includes(role));
/** Pricing sections follow the chosen roles, as in the old form. */
const isCustomer = computed(() => hasRole(props.options.customerRoleIds));
const isParty = computed(() => hasRole(props.options.partyRoleIds));

const lastPriceOptions = computed<Option[]>(() => [
    { value: 1, label: t('Yes') },
    { value: 0, label: t('No') },
]);
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <Card :title="t('Personal Information')" :description="t('Basic details used to identify the user.')">
                <div class="grid gap-4 md:grid-cols-2">
                    <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
                    <TextInput v-model="form.phone" :label="t('Phone')" type="tel" dir="ltr" :maxlength="255" :error="form.errors.phone" />
                </div>
            </Card>

            <Card :title="t('Login Credentials')" :description="t('Email and password used to sign in.')">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <TextInput v-model="form.email" :label="t('Email')" type="email" dir="ltr" required :maxlength="255" autocomplete="off" :error="form.errors.email" />
                    </div>
                    <TextInput
                        v-model="form.password"
                        :label="t('Password')"
                        type="password"
                        dir="ltr"
                        :required="!editing"
                        autocomplete="new-password"
                        :error="form.errors.password"
                        :hint="editing ? t('Leave blank to keep the current password.') : undefined"
                    />
                    <TextInput
                        v-model="form.password_confirmation"
                        :label="t('Confirm Password')"
                        type="password"
                        dir="ltr"
                        :required="!editing || form.password !== ''"
                        autocomplete="new-password"
                        :error="form.errors.password_confirmation"
                    />
                </div>
            </Card>
        </div>

        <div class="space-y-4">
            <Card :title="t('Roles & Access')" :description="t('Roles decide what the user can see and do.')">
                <div class="space-y-4">
                    <MultiSelect v-model="form.roles" :label="t('Roles')" :options="options.roleOptions" required :placeholder="t('Select Roles')" :error="rolesError" />
                    <MultiSelect
                        v-if="options.branchOptions"
                        v-model="form.branch_id"
                        single
                        :label="t('Branch')"
                        :options="options.branchOptions"
                        :placeholder="t('Select Branch')"
                        :error="form.errors.branch_id"
                    />
                </div>
            </Card>

            <Card v-if="isCustomer" :title="t('Customer Pricing')" :description="t('Margin added to cost when pricing this customer\'s sales.')">
                <div class="space-y-4">
                    <MultiSelect
                        v-model="form.customer_group_id"
                        single
                        :label="t('Customer Group')"
                        :options="options.customerGroupOptions"
                        :placeholder="t('No group')"
                        :error="form.errors.customer_group_id"
                    />
                    <TextInput
                        v-model="form.profit_margin"
                        :label="`${t('Profit Margin')} (%)`"
                        type="number"
                        dir="ltr"
                        step="0.01"
                        :min="0"
                        :max="1000"
                        :hint="t('Leave blank to use the customer group margin.')"
                        :error="form.errors.profit_margin"
                    />
                </div>
            </Card>

            <Card v-if="isParty" :title="t('Document Line Prices')">
                <div class="space-y-4">
                    <SelectInput
                        v-model="form.use_last_price"
                        :label="t('Use Last Price')"
                        :options="lastPriceOptions"
                        :placeholder="t('Follow the customer group')"
                        :hint="t('Fills a line with the last posted price for this party and item.')"
                        :error="form.errors.use_last_price"
                    />
                    <MultiSelect
                        v-model="form.price_list_id"
                        single
                        :label="t('Price List')"
                        :options="options.priceListOptions"
                        :placeholder="t('Follow the customer group')"
                        :error="form.errors.price_list_id"
                    />
                </div>
            </Card>
        </div>
    </div>
</template>
