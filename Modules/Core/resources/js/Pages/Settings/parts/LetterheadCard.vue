<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';

import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

import type { SettingsForm } from './types';

defineProps<{ form: InertiaForm<SettingsForm> }>();
</script>

<template>
    <Card :title="t('Print header')" :description="t('Shown at the top of every printed document, beside the site logo. The email is the contact email above.')">
        <div class="grid gap-4 md:grid-cols-2">
            <TextInput
                v-model="form.company_name"
                class="md:col-span-2"
                :label="t('Company Name')"
                :maxlength="255"
                :hint="t('Leave empty to use the site name.')"
                :error="form.errors.company_name"
            />
            <TextInput v-model="form.company_phone" :label="t('Phone')" dir="ltr" :maxlength="50" :error="form.errors.company_phone" />
            <TextInput v-model="form.company_fax" :label="t('Fax')" dir="ltr" :maxlength="50" :error="form.errors.company_fax" />
            <TextInput v-model="form.company_website" class="md:col-span-2" :label="t('Website')" dir="ltr" :maxlength="255" :error="form.errors.company_website" />
        </div>
    </Card>
</template>
