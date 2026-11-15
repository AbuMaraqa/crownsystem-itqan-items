<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

import MultiSelect from '@/Components/form/MultiSelect.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { can, t } from '@/lib/i18n';
import { Plus } from '@/lib/icons';

import type { Option } from '../parts/types';
import BranchQuickCreate, { type BranchFormOptions } from './parts/BranchQuickCreate.vue';

export interface WarehouseForm {
    name: string;
    code: string | null;
    branch_id: number | null;
    phone: string | null;
    email: string | null;
    latitude: string | number | null;
    longitude: string | number | null;
    is_active: boolean;
    address: string | null;
    description: string | null;
}

/** Form choices from WarehouseController::formOptions. */
export interface WarehouseFormOptions {
    branchOptions: Option[];
    /** Null when the user cannot add a branch. */
    branchForm: BranchFormOptions | null;
}

const props = defineProps<{ form: InertiaForm<WarehouseForm>; options: WarehouseFormOptions }>();

const branchOpen = ref(false);
let knownBranches: (number | string)[] = props.options.branchOptions.map((branch) => branch.value);

/** A branch added from the window comes back in the list: choose it. */
watch(
    () => props.options.branchOptions,
    (branches) => {
        const added = branches.find((branch) => !knownBranches.includes(branch.value));
        knownBranches = branches.map((branch) => branch.value);

        if (added) props.form.branch_id = Number(added.value);
    },
);
</script>

<template>
    <Card class="max-w-4xl" :title="t('General information')">
        <div class="grid gap-4 md:grid-cols-2">
            <TextInput v-model="form.name" class="md:col-span-2" :label="t('Name')" required :maxlength="255" :error="form.errors.name" />
            <TextInput v-model="form.code" :label="t('Code')" dir="ltr" :maxlength="255" :error="form.errors.code" />
            <div class="flex items-end gap-2">
                <MultiSelect v-model="form.branch_id" class="min-w-0 flex-1" single :label="t('Branch')" :placeholder="t('Select')" :options="options.branchOptions" :error="form.errors.branch_id" />
                <Button v-if="options.branchForm && can('Branch Create')" :aria-label="t('Add Branch')" @click="branchOpen = true"><Plus /></Button>
            </div>
            <TextInput v-model="form.phone" type="tel" :label="t('Phone')" dir="ltr" :maxlength="255" :error="form.errors.phone" />
            <TextInput v-model="form.email" type="email" :label="t('Email')" dir="ltr" :maxlength="255" :error="form.errors.email" />
            <TextInput v-model="form.latitude" type="number" step="any" min="-90" max="90" :label="t('Latitude')" dir="ltr" :error="form.errors.latitude" />
            <TextInput v-model="form.longitude" type="number" step="any" min="-180" max="180" :label="t('Longitude')" dir="ltr" :error="form.errors.longitude" />
            <Toggle v-model="form.is_active" class="md:col-span-2" :label="t('Active')" />
            <TextareaInput v-model="form.address" class="md:col-span-2" :label="t('Address')" :error="form.errors.address" />
            <TextareaInput v-model="form.description" class="md:col-span-2" :label="t('Description')" :error="form.errors.description" />
        </div>
    </Card>

    <BranchQuickCreate v-if="options.branchForm" v-model:open="branchOpen" :options="options.branchForm" />
</template>
