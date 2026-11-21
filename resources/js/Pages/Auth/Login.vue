<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

import AuthHeading from '@/Components/auth/AuthHeading.vue';
import Checkbox from '@/Components/form/Checkbox.vue';
import PasswordInput from '@/Components/form/PasswordInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Alert from '@/Components/ui/Alert.vue';
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';

defineOptions({ layout: GuestLayout });
defineProps<{ status?: string }>();

const form = useForm({ email: '', password: '', remember: false });

function submit(): void {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <AuthHeading :title="t('Log in')" :description="t('Please sign in to continue')" />
    <Alert v-if="status" tone="success">{{ status }}</Alert>
    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <TextInput v-model="form.email" :label="t('Email')" type="email" placeholder="email@example.com" autocomplete="username" dir="ltr" required :error="form.errors.email" />
        <PasswordInput v-model="form.password" :label="t('Password')" autocomplete="current-password" required :error="form.errors.password">
            <template #aside>
                <Link href="/forgot-password" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ t('Forgot Password?') }}</Link>
            </template>
        </PasswordInput>
        <Checkbox v-model="form.remember" :label="t('Remember me')" />
        <Button type="submit" variant="primary" class="w-full" :loading="form.processing">{{ t('Log in') }}</Button>
    </form>
</template>
