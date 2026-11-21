<script setup lang="ts">
import { ref, useId } from 'vue';

import { t } from '@/lib/i18n';
import { Eye, EyeClosed } from '@/lib/icons';

import { controlClasses } from './controlClasses';
import Field from './Field.vue';

/** A password field with a button that shows or hides what was typed. */
defineProps<{
    label?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    placeholder?: string;
    autocomplete?: string;
    autofocus?: boolean;
}>();

const model = defineModel<string>();
const id = useId();
const visible = ref(false);
</script>

<template>
    <Field :id="id" :label="label" :error="error" :hint="hint" :required="required">
        <!-- A link on the label's line, e.g. "Forgot password?" -->
        <template v-if="$slots.aside" #aside><slot name="aside" /></template>
        <!-- A password reads left to right in every language: the field and its button keep that order,
             and on an Arabic page the text stays on the right whether it is hidden or shown. -->
        <div class="relative" dir="ltr">
            <input
                :id="id"
                :value="model ?? ''"
                :type="visible ? 'text' : 'password'"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :autofocus="autofocus"
                :required="required"
                :aria-invalid="!!error"
                :class="[controlClasses, 'h-control pe-10 rtl:text-right']"
                @input="model = ($event.target as HTMLInputElement).value"
            />
            <button
                type="button"
                class="absolute inset-y-0 end-0 flex w-10 items-center justify-center rounded-e-control text-gray-500 outline-none hover:text-gray-900 focus-visible:text-brand-500 dark:text-gray-400 dark:hover:text-white"
                :aria-label="visible ? t('Hide password') : t('Show password')"
                :aria-pressed="visible"
                @click="visible = !visible"
            >
                <EyeClosed v-if="visible" class="size-[18px]" />
                <Eye v-else class="size-[18px]" />
            </button>
        </div>
    </Field>
</template>
