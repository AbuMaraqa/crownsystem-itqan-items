<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';

import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { t } from '@/lib/i18n';
import { X } from '@/lib/icons';

import type { SettingsForm } from './types';

/** Cost items (customs, freight...) added to purchases. A removed item is soft-deleted, so its costs stay. */
const props = defineProps<{ form: InertiaForm<SettingsForm> }>();

const errorOf = (index: number, field: string): string | undefined => (props.form.errors as Record<string, string | undefined>)[`additional_cost_types.${index}.${field}`];

function add(): void {
    props.form.additional_cost_types.push({ id: null, name: '', is_active: true });
}

function remove(index: number): void {
    props.form.additional_cost_types.splice(index, 1);
}
</script>

<template>
    <Card :title="t('Manage Cost Items')">
        <div class="space-y-3">
            <p v-if="form.additional_cost_types.length === 0" class="rounded-lg border border-dashed border-line p-6 text-center text-sm text-muted">
                {{ t('No cost items yet') }}
            </p>
            <div v-for="(item, index) in form.additional_cost_types" :key="item.id ?? `new-${index}`" class="flex items-start gap-3 rounded-lg border border-line p-3">
                <div class="flex-1">
                    <TextInput v-model="item.name" :label="t('Cost Item')" required :maxlength="255" :error="errorOf(index, 'name')" />
                </div>
                <div class="pt-8">
                    <Toggle v-model="item.is_active" :label="t('Active')" />
                </div>
                <Button variant="ghost" size="icon" class="mt-7" :aria-label="t('Remove')" @click="remove(index)"><X class="size-4" /></Button>
            </div>
            <Button @click="add">{{ t('Add Cost Item') }}</Button>
        </div>
    </Card>
</template>
