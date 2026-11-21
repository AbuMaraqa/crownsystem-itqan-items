<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import AuthHeading from '@/Components/auth/AuthHeading.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';

defineOptions({ layout: GuestLayout });

const recovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });
</script>

<template>
    <AuthHeading
        :title="t('Two-factor code')"
        :description="
            recovery
                ? t('Please confirm access to your account by entering one of your emergency recovery codes.')
                : t('Please confirm access to your account by entering the authentication code provided by your authenticator application.')
        "
    />
    <form class="flex flex-col gap-5" @submit.prevent="form.post('/two-factor-challenge')">
        <TextInput v-if="!recovery" v-model="form.code" :label="t('Code from your authenticator app')" autocomplete="one-time-code" dir="ltr" :error="form.errors.code" />
        <TextInput v-else v-model="form.recovery_code" :label="t('Recovery code')" autocomplete="one-time-code" dir="ltr" :error="form.errors.recovery_code" />
        <Button type="submit" variant="primary" class="w-full" :loading="form.processing">{{ t('Log in') }}</Button>
        <button type="button" class="text-center text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400" @click="recovery = !recovery">
            {{ recovery ? t('Use a code from your app') : t('Use a recovery code') }}
        </button>
    </form>
</template>
