<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';

import AuthHeading from '@/Components/auth/AuthHeading.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Alert from '@/Components/ui/Alert.vue';
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';
import { ChevronLeft } from '@/lib/icons';

defineOptions({ layout: GuestLayout });
defineProps<{ status?: string }>();

const form = useForm({ email: '' });
</script>

<template>
    <AuthHeading :title="t('Forgot your password?')" :description="t('Enter your email and we will send you a link to choose a new password.')" />
    <Alert v-if="status" tone="success">{{ status }}</Alert>
    <form class="flex flex-col gap-5" @submit.prevent="form.post('/forgot-password')">
        <TextInput v-model="form.email" :label="t('Email')" type="email" placeholder="email@example.com" autocomplete="username" dir="ltr" required :error="form.errors.email" />
        <Button type="submit" variant="primary" class="w-full" :loading="form.processing">{{ t('Send reset link') }}</Button>
        <Link href="/login" class="inline-flex items-center justify-center gap-1 text-sm font-medium text-gray-700 hover:text-brand-500 dark:text-gray-300">
            <ChevronLeft class="size-4 rtl:rotate-180" />{{ t('Back to log in') }}
        </Link>
    </form>
</template>
