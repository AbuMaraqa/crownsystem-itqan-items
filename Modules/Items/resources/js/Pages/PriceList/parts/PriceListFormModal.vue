<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

export interface PriceListRow {
    id: number;
    name: string;
    same_buy_sell: boolean;
    prices_count: number;
    sort_order: number;
    is_active: boolean;
}

interface PriceListForm {
    name: string;
    sort_order: number | string;
    same_buy_sell: boolean;
    is_active: boolean;
}

/** `list` null adds a new list. */
const props = defineProps<{ list: PriceListRow | null }>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm<PriceListForm>({ name: '', sort_order: 0, same_buy_sell: false, is_active: true });

watch(
    open,
    (isOpen) => {
        if (!isOpen) return;
        form.clearErrors();
        form.defaults({
            name: props.list?.name ?? '',
            sort_order: props.list?.sort_order ?? 0,
            same_buy_sell: props.list?.same_buy_sell ?? false,
            is_active: props.list?.is_active ?? true,
        });
        form.reset();
    },
    { immediate: true },
);

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    if (props.list) {
        form.put(route('price-lists.update', props.list.id), options);
    } else {
        form.post(route('price-lists.store'), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="list ? t('Edit Price List') : t('Add Price List')" width="max-w-md">
        <form class="grid gap-4" @submit.prevent="submit">
            <TextInput v-model="form.name" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
            <TextInput v-model="form.sort_order" :label="t('Sort Order')" type="number" min="0" step="1" required :error="form.errors.sort_order" />
            <Toggle v-model="form.same_buy_sell" :label="t('Buy Price Equals Sell Price')" />
            <Toggle v-model="form.is_active" :label="t('Is Active')" />
        </form>
        <template #footer>
            <Button @click="open = false">{{ t('Cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
