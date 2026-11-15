<script setup lang="ts">
import { reactive, ref, watch } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';

import { HttpError, postJson } from './http';
import type { Option } from './types';

/**
 * Create a new choice without leaving the form (Modules\Accounting\Support\QuickCreate): a party
 * (`party`: name, email, phone — supplier, shipping company...) or a simple named record (`name`:
 * cost item, attachment category...). `url` answers with the new `{ value, label }`.
 */
const props = withDefaults(defineProps<{ url: string; title: string; fields?: 'party' | 'name'; body?: Record<string, unknown> }>(), { fields: 'party', body: () => ({}) });

const emit = defineEmits<{ created: [option: Option] }>();

const open = defineModel<boolean>('open', { required: true });

const form = reactive({ name: '', email: '', phone: '' });
const errors = ref<Record<string, string>>({});
const saving = ref(false);

watch(open, (isOpen) => {
    if (!isOpen) return;

    Object.assign(form, { name: '', email: '', phone: '' });
    errors.value = {};
});

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};

    try {
        const payload = props.fields === 'party' ? { ...form } : { name: form.name };
        const option = await postJson<Option>(props.url, { ...props.body, ...payload });

        emit('created', option);
        open.value = false;
    } catch (error) {
        if (error instanceof HttpError && error.body?.errors) {
            errors.value = Object.fromEntries(Object.entries(error.body.errors).map(([key, messages]) => [key, messages[0]]));
        } else {
            errors.value = { name: error instanceof Error ? error.message : t('Something went wrong') };
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="title" width="max-w-md">
        <form class="grid gap-4" @submit.prevent="submit">
            <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="errors.name" />
            <template v-if="fields === 'party'">
                <TextInput v-model="form.email" type="email" dir="ltr" :label="t('Email')" :maxlength="255" :error="errors.email" />
                <TextInput v-model="form.phone" dir="ltr" :label="t('Phone')" :maxlength="255" :error="errors.phone" />
            </template>
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="saving" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
