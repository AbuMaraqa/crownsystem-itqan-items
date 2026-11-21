<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import AuthHeading from '@/Components/auth/AuthHeading.vue';
import PasswordInput from '@/Components/form/PasswordInput.vue';
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';

defineOptions({ layout: GuestLayout });

const form = useForm({ password: '' });

function submit(): void {
    form.post('/user/confirm-password', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthHeading :title="t('Confirm Password')" :description="t('This is a secure area of the application. Please confirm your password before continuing.')" />
    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <PasswordInput v-model="form.password" :label="t('Password')" autocomplete="current-password" required autofocus :error="form.errors.password" />
        <Button type="submit" variant="primary" class="w-full" :loading="form.processing">{{ t('Confirm') }}</Button>
    </form>
</template>
