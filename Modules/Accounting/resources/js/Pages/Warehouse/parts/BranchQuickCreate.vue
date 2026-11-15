<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import { t } from '@/lib/i18n';
import { route } from '@/lib/route';

import type { Option } from '../../parts/types';

/** The branch field's "new branch" window (the old createOptionForm). */
export interface BranchFormOptions {
    countries: Option[];
    cities: (Option & { country_id: number | null })[];
    statuses: Option[];
}

const props = defineProps<{ options: BranchFormOptions }>();
const emit = defineEmits<{ created: [] }>();

const open = defineModel<boolean>('open', { required: true });

const blank = () => ({
    name: '',
    country_id: null as number | null,
    city_id: null as number | null,
    phone: null as string | null,
    email: null as string | null,
    opening_time: '08:00',
    closing_time: '17:00',
    status: Number(props.options.statuses[0]?.value ?? 1),
    address: '',
    description: '',
});

const form = useForm(blank());

const visibleCities = computed(() => props.options.cities.filter((city) => city.country_id === form.country_id));

watch(
    () => form.country_id,
    () => {
        if (!visibleCities.value.some((city) => city.value === form.city_id)) form.city_id = null;
    },
);

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank());
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.post(route('accounting.warehouses.branches.store'), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('created');
        },
    });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('Add Branch')" width="max-w-2xl">
        <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submit">
            <TextInput v-model="form.name" class="md:col-span-2" :label="t('Name')" required :error="form.errors.name" />
            <MultiSelect v-model="form.country_id" single :label="t('Country')" required :options="options.countries" :error="form.errors.country_id" />
            <MultiSelect v-model="form.city_id" single :label="t('City')" required :options="visibleCities" :error="form.errors.city_id" />
            <TextInput v-model="form.phone" type="tel" dir="ltr" :label="t('Phone')" :error="form.errors.phone" />
            <TextInput v-model="form.email" type="email" dir="ltr" :label="t('Email')" :error="form.errors.email" />
            <TextInput v-model="form.opening_time" type="time" :label="t('Opening Time')" :error="form.errors.opening_time" />
            <TextInput v-model="form.closing_time" type="time" :label="t('Closing Time')" :error="form.errors.closing_time" />
            <SelectInput v-model="form.status" :label="t('Status')" required :options="options.statuses" :error="form.errors.status" />
            <TextareaInput v-model="form.address" class="md:col-span-2" :label="t('Address')" :error="form.errors.address" />
            <TextareaInput v-model="form.description" class="md:col-span-2" :label="t('Description')" :error="form.errors.description" />
            <div class="flex justify-end gap-2 md:col-span-2">
                <Button @click="open = false">{{ t('Cancel') }}</Button>
                <Button variant="primary" type="submit" :loading="form.processing">{{ t('Add Branch') }}</Button>
            </div>
        </form>
    </Modal>
</template>
