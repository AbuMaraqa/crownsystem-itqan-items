<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import FormActions from '@/Components/form/FormActions.vue';
import type { Option } from '@/Components/form/SelectInput.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import JournalEntryFields, { type JournalEntryForm } from './JournalEntryFields.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    entry: JournalEntryForm;
    accounts: Option[];
    branches: Option[];
    currencies: Option[];
    defaultCurrencyId: number | null;
}>();

const form = useForm<JournalEntryForm>(props.entry);
</script>

<template>
    <PageHeader :title="t('Add Journal Entry')" :parents="[{ title: t('Journal Entries'), href: route('accounting.journal-entries.index') }]" />
    <form @submit.prevent="form.post(route('accounting.journal-entries.store'))">
        <JournalEntryFields :form="form" :accounts="accounts" :branches="branches" :currencies="currencies" :default-currency-id="defaultCurrencyId" />
        <FormActions :processing="form.processing" :cancel-href="route('accounting.journal-entries.index')" :submit-label="t('Save')" />
    </form>
</template>
