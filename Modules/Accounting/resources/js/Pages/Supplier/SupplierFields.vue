<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';

import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';

export interface SupplierForm {
    name: string;
    /** A supplier may have no email. */
    email: string | null;
    phone: string | null;
    tax_number: string | null;
    address: string | null;
}

defineProps<{ form: InertiaForm<SupplierForm> }>();
</script>

<template>
    <Card class="max-w-3xl" :title="t('Supplier Information')">
        <div class="grid gap-4 md:grid-cols-2">
            <TextInput v-model="form.name" class="md:col-span-2" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
            <TextInput v-model="form.email" type="email" :label="t('Email')" dir="ltr" autocomplete="off" :maxlength="255" :error="form.errors.email" />
            <TextInput v-model="form.phone" type="tel" :label="t('Phone')" dir="ltr" :maxlength="255" :error="form.errors.phone" />
            <TextInput v-model="form.tax_number" :label="t('Tax Number')" dir="ltr" :maxlength="255" :error="form.errors.tax_number" />
            <TextareaInput v-model="form.address" class="md:col-span-2" :label="t('Address')" :error="form.errors.address" />
        </div>
    </Card>
</template>
