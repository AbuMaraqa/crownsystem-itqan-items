<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';

defineOptions({ layout: GuestLayout });

const form = useForm({ password: '' });

function submit(): void {
    form.post('/user/confirm-password', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head :title="t('Confirm Password')" />
    <h1 class="mb-2 text-base font-semibold">{{ t('Confirm Password') }}</h1>
    <p class="mb-4 text-sm text-muted">{{ t('This is a secure area of the application. Please confirm your password before continuing.') }}</p>
    <form class="space-y-4" @submit.prevent="submit">
        <TextInput v-model="form.password" :label="t('Password')" type="password" autocomplete="current-password" dir="ltr" required autofocus :error="form.errors.password" />
        <Button type="submit" variant="primary" class="w-full" :loading="form.processing">{{ t('Confirm') }}</Button>
    </form>
</template>
