<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import FormActions from '@/Components/form/FormActions.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import UserFields, { type UserForm, type UserFormOptions } from './UserFields.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<UserFormOptions & { user: Omit<UserForm, 'password' | 'password_confirmation'> & { id: number } }>();

const { id: _id, ...values } = props.user;
const form = useForm<UserForm>({ ...values, password: '', password_confirmation: '' });

const { user: _user, ...rest } = props;
const options = computed<UserFormOptions>(() => rest);
</script>

<template>
    <PageHeader :title="t('Edit User')" :parents="[{ title: t('Users List'), href: route('users.index') }]" />
    <form @submit.prevent="form.put(route('users.update', user.id))">
        <UserFields :form="form" :options="options" editing />
        <FormActions :processing="form.processing" :cancel-href="route('users.index')" :submit-label="t('Update')" />
    </form>
</template>
