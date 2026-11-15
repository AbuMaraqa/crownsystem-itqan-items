<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import SelectInput, { type Option } from '@/Components/form/SelectInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { ItemTypeModes, ItemTypeProfileRow } from './types';

/** The selects hand back a string or a number; the server casts them. */
interface ItemTypeProfileForm {
    code: string;
    name: string;
    sort_order: number | string | null;
    quantity_decimals: number | string | null;
    price_decimals: number | string | null;
    notes_count: number | string | null;
    date_mode: string | number | null;
    bonus_mode: string | number | null;
    serial_mode: string | number | null;
    sub_item_mode: string | number | null;
    dimensions: number | string | null;
    has_color: boolean;
    has_size: boolean;
    is_active: boolean;
}

/** `profile` null adds a new type. */
const props = defineProps<{ profile: ItemTypeProfileRow | null; modes: ItemTypeModes }>();

const open = defineModel<boolean>('open', { required: true });

const range = (max: number): Option[] => Array.from({ length: max + 1 }, (_, value) => ({ value, label: String(value) }));
const decimals = range(5);
const upToThree = range(3);

function values(profile: ItemTypeProfileRow | null): ItemTypeProfileForm {
    return {
        code: profile?.code ?? '',
        name: profile?.name ?? '',
        sort_order: profile?.sort_order ?? 0,
        quantity_decimals: profile ? profile.quantity_decimals : 2,
        price_decimals: profile ? profile.price_decimals : 2,
        notes_count: profile?.notes_count ?? 0,
        date_mode: profile?.date_mode ?? 'none',
        bonus_mode: profile?.bonus_mode ?? 'none',
        serial_mode: profile?.serial_mode ?? 'none',
        sub_item_mode: profile?.sub_item_mode ?? 'none',
        dimensions: profile?.dimensions ?? 0,
        has_color: profile?.has_color ?? false,
        has_size: profile?.has_size ?? false,
        is_active: profile?.is_active ?? true,
    };
}

const form = useForm<ItemTypeProfileForm>(values(null));

watch(
    open,
    (isOpen) => {
        if (!isOpen) return;
        form.clearErrors();
        form.defaults(values(props.profile));
        form.reset();
    },
    { immediate: true },
);

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    if (props.profile) {
        form.put(route('item-type-profiles.update', props.profile.id), options);
    } else {
        form.post(route('item-type-profiles.store'), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="profile ? t('Edit Item Type') : t('Add Item Type')" width="max-w-3xl">
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid gap-4 md:grid-cols-3">
                <TextInput v-model="form.code" :label="t('Code')" required :maxlength="50" dir="ltr" :error="form.errors.code" />
                <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
                <TextInput v-model="form.sort_order" :label="t('Sort Order')" type="number" min="0" step="1" required :error="form.errors.sort_order" />
                <!-- No choice: the field is hidden on the document line. -->
                <SelectInput v-model="form.quantity_decimals" :label="t('Quantity Decimals')" :placeholder="t('Hidden')" :options="decimals" :error="form.errors.quantity_decimals" />
                <SelectInput v-model="form.price_decimals" :label="t('Price Decimals')" :placeholder="t('Hidden')" :options="decimals" :error="form.errors.price_decimals" />
                <SelectInput v-model="form.notes_count" :label="t('Line Notes')" :options="upToThree" :error="form.errors.notes_count" />
                <SelectInput v-model="form.date_mode" :label="t('Date')" required :options="modes.date" :error="form.errors.date_mode" />
                <SelectInput v-model="form.bonus_mode" :label="t('Free Quantity')" required :options="modes.bonus" :error="form.errors.bonus_mode" />
                <SelectInput v-model="form.serial_mode" :label="t('Serial')" required :options="modes.serial" :error="form.errors.serial_mode" />
                <SelectInput v-model="form.sub_item_mode" :label="t('Sub Items')" required :options="modes.subItem" :error="form.errors.sub_item_mode" />
                <SelectInput v-model="form.dimensions" :label="t('Dimensions')" :options="upToThree" :error="form.errors.dimensions" />
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                <Toggle v-model="form.has_color" :label="t('Has Color')" />
                <Toggle v-model="form.has_size" :label="t('Has Size')" />
                <Toggle v-model="form.is_active" :label="t('Is Active')" />
            </div>
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
