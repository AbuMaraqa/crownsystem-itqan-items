<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import UserFields, { type UserForm, type UserFormOptions } from './UserFields.vue';

defineOptions({ layout: AdminLayout });
const props = defineProps<UserFormOptions>();

const form = useForm<UserForm>({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    roles: [],
    branch_id: null,
    customer_group_id: null,
    profit_margin: null,
    use_last_price: null,
    price_list_id: null,
});
</script>

<template>
    <PageHeader :title="t('Add User')" :parents="[{ title: t('Users List'), href: route('users.index') }]" />
    <form @submit.prevent="form.post(route('users.store'))">
        <UserFields :form="form" :options="props" />
        <FormActions :processing="form.processing" :cancel-href="route('users.index')" :submit-label="t('Add User')" />
    </form>
</template>
