<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import AuthHeading from '@/Components/auth/AuthHeading.vue';
import PasswordInput from '@/Components/form/PasswordInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';

defineOptions({ layout: GuestLayout });

const props = defineProps<{ email: string; token: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <AuthHeading :title="t('Choose a new password')" />
    <form class="flex flex-col gap-5" @submit.prevent="form.post('/reset-password')">
        <TextInput v-model="form.email" :label="t('Email')" type="email" autocomplete="username" dir="ltr" required :error="form.errors.email" />
        <PasswordInput v-model="form.password" :label="t('New password')" autocomplete="new-password" required :error="form.errors.password" />
        <PasswordInput v-model="form.password_confirmation" :label="t('Confirm the password')" autocomplete="new-password" required />
        <Button type="submit" variant="primary" class="w-full" :loading="form.processing">{{ t('Save the new password') }}</Button>
    </form>
</template>
