<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch } from 'vue';

import Field from '@/Components/form/Field.vue';
import ImageUpload from '@/Components/form/ImageUpload.vue';
import MultiSelect from '@/Components/form/MultiSelect.vue';
import RemoteSelect from '@/Components/form/RemoteSelect.vue';
import SelectInput from '@/Components/form/SelectInput.vue';
import TextareaInput from '@/Components/form/TextareaInput.vue';
import TextInput from '@/Components/form/TextInput.vue';
import Toggle from '@/Components/form/Toggle.vue';
import { controlClasses } from '@/Components/form/controlClasses';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import { can, t } from '@/lib/i18n';
import { Check, Plus, Returns } from '@/lib/icons';
import { route } from '@/lib/route';

import { getJson, type CardRequest, type ItemCardPayload } from './types';

/**
 * The item card as in the old system: barcode, item name, item number (automatic, read only), the
 * nature and type, the main item it belongs to and the cost, then the tabs (price lists, service,
 * information, images, notes, supplier). A hidden field is not sent, so its saved value stays.
 * Adding a piece from the tree fixes the type and the main item.
 */
const props = defineProps<{
    /** What to open; a new object opens the card again. */
    request: CardRequest | null;
    /** The tree's context for a new item. */
    context: { categoryId: number | null; productId: number | null; highlightedId: number | null };
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ create: [] }>();

type Num = number | string | null;

interface CardForm {
    barcode: string | null;
    name: string;
    sku: string | null;
    pricing_type: string;
    item_type: string;
    main_product_id: number | null;
    service_kind: string | null;
    service_cost_source: string | null;
    revenue_account_id: number | null;
    cost_account_id: number | null;
    base_price: Num;
    length: Num;
    weight_per_meter: Num;
    synonym_product_id: number | null;
    shelf: string | null;
    site_area: Num;
    min_limit_1: Num;
    min_limit_2: Num;
    max_limit: Num;
    color: string | null;
    unit_id: number | null;
    item_type_profile_id: number | null;
    bundle_count: Num;
    profile_thickness: string | null;
    is_open_profile: number | null;
    area: Num;
    short_description: string | null;
    apply_to_colors: boolean;
    apply_to_color_ids: number[];
    supplier_ids: number[];
    image: File | null;
    remove_image: boolean;
    category_id: number | null;
    fixed_main_id: number | null;
}

const payload = shallowRef<ItemCardPayload | null>(null);
const loading = ref(false);
const failed = ref(false);
/** The tab the card is on; it stays after a save. */
const tab = defineModel<string>('tab', { default: 'information' });
const removedImages = ref<(number | string)[]>([]);
const form = useForm<CardForm>(blank());

function blank(): CardForm {
    return {
        barcode: null,
        name: '',
        sku: null,
        pricing_type: 'simple',
        item_type: 'goods',
        main_product_id: null,
        service_kind: null,
        service_cost_source: null,
        revenue_account_id: null,
        cost_account_id: null,
        base_price: null,
        length: null,
        weight_per_meter: null,
        synonym_product_id: null,
        shelf: null,
        site_area: null,
        min_limit_1: null,
        min_limit_2: null,
        max_limit: null,
        color: null,
        unit_id: null,
        item_type_profile_id: null,
        bundle_count: null,
        profile_thickness: null,
        is_open_profile: null,
        area: null,
        short_description: null,
        apply_to_colors: false,
        apply_to_color_ids: [],
        supplier_ids: [],
        image: null,
        remove_image: false,
        category_id: null,
        fixed_main_id: null,
    };
}

const productId = computed(() => payload.value?.id ?? null);
const fixedMain = computed(() => !productId.value && !!props.request?.fixedMainId);
const isWeight = computed(() => form.pricing_type === 'weight');
const isService = computed(() => form.item_type === 'service');
const isPiece = computed(() => isWeight.value && !!form.main_product_id);
const options = computed(() => payload.value?.options);
const colorsListId = 'item-card-colors';

async function load(request: CardRequest): Promise<void> {
    loading.value = true;
    failed.value = false;
    form.clearErrors();

    try {
        const url = request.productId
            ? route('products.tree.items.edit', request.productId)
            : route('products.tree.items.create', {
                  category: (request.underCategory ? request.categoryId : (request.categoryId ?? props.context.categoryId)) ?? undefined,
                  product: request.underCategory ? undefined : (props.context.productId ?? undefined),
                  highlighted: request.underCategory ? undefined : (props.context.highlightedId ?? undefined),
                  fixed_main: request.fixedMainId ?? undefined,
              });
        const data = await getJson<ItemCardPayload>(url);

        payload.value = data;
        removedImages.value = [];
        const state = { ...blank(), ...(data.state as Partial<CardForm>) };
        state.category_id = request.underCategory ? (request.categoryId ?? null) : (request.categoryId ?? props.context.categoryId);
        state.fixed_main_id = data.id ? null : (request.fixedMainId ?? null);
        form.defaults(state);
        form.reset();
        tab.value = request.tab ?? 'information';
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.request,
    (request) => {
        if (request) void load(request);
    },
);

// Under a main item everything is by weight; a service follows no main item.
watch(
    () => form.main_product_id,
    (value) => {
        if (value) form.pricing_type = 'weight';
    },
);
watch(
    () => form.item_type,
    (value) => {
        if (value === 'service') form.main_product_id = null;
    },
);
// An aluminium item type makes the item by weight (the model applies the same rule on save).
watch(
    () => form.item_type_profile_id,
    (value) => {
        if (options.value?.profiles.find((profile) => String(profile.value) === String(value))?.aluminium) form.pricing_type = 'weight';
    },
);

const tabs = computed(() =>
    [
        { value: 'prices', label: t('Price Lists') },
        ...(isService.value ? [{ value: 'service', label: t('Service Settings') }] : []),
        { value: 'information', label: t('Information') },
        { value: 'images', label: t('Images') },
        { value: 'notes', label: t('Notes') },
        { value: 'suppliers', label: t('Supplier') },
    ].map((item) => ({ ...item, hasError: tabFields[item.value]?.some((field) => form.errors[field as keyof CardForm]) })),
);

const tabFields: Record<string, string[]> = {
    prices: ['base_price'],
    service: ['service_kind', 'service_cost_source', 'revenue_account_id', 'cost_account_id'],
    information: ['length', 'weight_per_meter', 'synonym_product_id', 'shelf', 'site_area', 'min_limit_1', 'min_limit_2', 'max_limit', 'color', 'unit_id', 'item_type_profile_id', 'bundle_count', 'profile_thickness', 'is_open_profile', 'area'],
    images: ['image'],
    notes: ['short_description'],
    suppliers: ['supplier_ids'],
};

const mainHint = computed(() => (form.main_product_id ? (payload.value?.main_sku ?? undefined) : (payload.value?.parent?.code ?? undefined)));
const mainPlaceholder = computed(() => payload.value?.parent?.name || t('None — this is a main item'));
const cost = computed(() => Number(payload.value?.average_cost ?? 0).toFixed(2));

/** What the card shows is what it sends: a hidden field keeps its saved value. */
function visibleData(data: CardForm): Record<string, unknown> {
    const hidden = new Set<string>(['sku']);

    if (isWeight.value) hidden.add('base_price');
    else ['bundle_count', 'profile_thickness', 'is_open_profile', 'area'].forEach((field) => hidden.add(field));
    if (!isService.value) ['service_kind', 'service_cost_source', 'revenue_account_id', 'cost_account_id'].forEach((field) => hidden.add(field));
    if (!payload.value?.can_edit_limits) ['min_limit_1', 'min_limit_2', 'max_limit'].forEach((field) => hidden.add(field));
    if (!payload.value?.other_colors.length) ['apply_to_colors', 'apply_to_color_ids'].forEach((field) => hidden.add(field));
    if (!data.image) hidden.add('image');

    return Object.fromEntries(Object.entries(data).filter(([key]) => !hidden.has(key)));
}

function submit(): void {
    if (form.processing) return;

    form.remove_image = removedImages.value.length > 0;
    const withImage = !!form.image;
    const options = { preserveState: true, preserveScroll: true, forceFormData: withImage };

    if (productId.value) {
        form.transform((data) => ({ ...visibleData(data), ...(withImage ? { _method: 'put' } : {}) }));

        if (withImage) form.post(route('products.tree.items.update', productId.value), options);
        else form.put(route('products.tree.items.update', productId.value), options);
    } else {
        form.transform((data) => visibleData(data)).post(route('products.tree.items.store'), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="productId ? t('Edit Item') : t('New Item')" width="max-w-5xl">
        <p v-if="loading && !payload" class="py-10 text-center text-sm text-muted">{{ t('Loading...') }}</p>
        <p v-else-if="failed" class="py-10 text-center text-sm text-danger">{{ t('The card could not be loaded.') }}</p>

        <form v-else-if="payload && options" id="item-card-form" class="space-y-5" :class="{ 'opacity-60': loading }" @submit.prevent="submit">
            <!-- The header as in the old card: barcode, name, number, then nature, type, belongs to, cost. -->
            <div class="grid gap-4 md:grid-cols-4">
                <TextInput v-model="form.barcode" :label="t('Barcode')" :error="form.errors.barcode" :maxlength="255" dir="ltr" />
                <div class="md:col-span-2">
                    <TextInput v-model="form.name" :label="t('Item Name')" :error="form.errors.name" required :maxlength="255" />
                </div>
                <TextInput :model-value="form.sku ?? ''" :label="t('Item Number')" :placeholder="t('Automatic')" disabled dir="ltr" />

                <SelectInput v-model="form.item_type" :label="t('Item Nature')" :options="options.item_types" :error="form.errors.item_type" :disabled="fixedMain" required />
                <SelectInput v-model="form.pricing_type" :label="t('Type')" :options="options.pricing_types" :error="form.errors.pricing_type" :disabled="fixedMain" required />
                <div v-if="!isService" class="md:col-span-2">
                    <TextInput v-if="fixedMain" :model-value="payload.labels.main_product_id[0]?.label ?? ''" :label="t('Belongs to Item')" :hint="mainHint" disabled />
                    <RemoteSelect
                        v-else
                        v-model="form.main_product_id"
                        :url="route('products.tree.lookup', { type: 'weight', except: productId ?? undefined })"
                        :initial="payload.labels.main_product_id"
                        :label="t('Belongs to Item')"
                        :placeholder="mainPlaceholder"
                        :hint="mainHint"
                        :error="form.errors.main_product_id"
                    />
                </div>
                <Field v-if="!isPiece" :label="t('Average Cost')">
                    <div class="flex h-10 items-center rounded-lg bg-gray-50 px-3 text-sm text-gray-600 tabular-nums ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-400 dark:ring-white/20" dir="ltr">{{ cost }}</div>
                </Field>
            </div>

            <!-- Saving a piece can also go to the same profile under other colours (by barcode). -->
            <div v-if="payload.other_colors.length" class="space-y-3 rounded-lg border border-line p-3">
                <Toggle v-model="form.apply_to_colors" :label="t('Apply to the same profile in other colors')" />
                <fieldset v-if="form.apply_to_colors" class="space-y-2">
                    <legend class="mb-2 text-sm text-muted">{{ t('Weight per meter, length and name are applied to the items you tick.') }}</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="color in payload.other_colors" :key="color.value" class="inline-flex items-center gap-2 text-sm">
                            <input v-model="form.apply_to_color_ids" type="checkbox" :value="color.value" class="size-4 rounded accent-brand-500" />
                            <span>{{ color.label }}</span>
                        </label>
                    </div>
                </fieldset>
            </div>

            <Tabs v-model="tab" :tabs="tabs">
                <template #prices>
                    <p v-if="isWeight" class="mb-4 rounded-lg bg-surface px-3 py-2 text-sm text-muted">
                        {{ isPiece ? t('A piece has no price. The amount is calculated on its main item.') : t('This item groups its pieces. The price per kg comes from the price lists and can be changed on the document line.') }}
                    </p>
                    <div v-if="!isWeight" class="grid gap-4 md:grid-cols-4">
                        <TextInput v-model="form.base_price" type="number" step="any" :label="t('Base Price')" :error="form.errors.base_price" required />
                    </div>
                </template>

                <template #service>
                    <div class="grid gap-4 md:grid-cols-4">
                        <SelectInput v-model="form.service_kind" :label="t('Service Kind')" :options="options.service_kinds" :error="form.errors.service_kind" />
                        <SelectInput v-model="form.service_cost_source" :label="t('Default Cost Source')" :options="options.cost_sources" :error="form.errors.service_cost_source" />
                        <MultiSelect v-model="form.revenue_account_id" single :options="options.revenue_accounts" :label="t('Revenue Account')" :placeholder="t('Default account')" />
                        <MultiSelect v-model="form.cost_account_id" single :options="options.cost_accounts" :label="t('Cost Account')" :placeholder="t('Default account')" />
                    </div>
                    <p class="mt-3 text-sm text-muted">{{ t('The service price is the base price and its cost is entered on the document line. Pricing type "By Weight" makes the service priced per kg by default.') }}</p>
                </template>

                <template #information>
                    <div class="grid gap-4 md:grid-cols-4">
                        <TextInput v-model="form.length" type="number" step="any" :label="`${t('Piece Length')} (${t('m')})`" :error="form.errors.length" />
                        <TextInput v-model="form.weight_per_meter" type="number" step="any" :label="`${t('Weight per Meter')} (${t('kg/m')})`" :error="form.errors.weight_per_meter" />
                        <RemoteSelect
                            v-model="form.synonym_product_id"
                            :url="route('products.tree.lookup', { except: productId ?? undefined })"
                            :initial="payload.labels.synonym_product_id"
                            :label="t('Synonym Item')"
                            :error="form.errors.synonym_product_id"
                        />
                        <TextInput v-model="form.shelf" :label="t('Shelf')" :error="form.errors.shelf" :maxlength="50" />
                        <TextInput v-model="form.site_area" type="number" step="any" :label="t('Section Area')" :error="form.errors.site_area" />
                        <TextInput v-model="form.min_limit_1" type="number" step="any" min="0" :label="t('Min Limit 1')" :error="form.errors.min_limit_1" :disabled="!payload.can_edit_limits" />
                        <TextInput v-model="form.min_limit_2" type="number" step="any" min="0" :label="t('Min Limit 2')" :error="form.errors.min_limit_2" :disabled="!payload.can_edit_limits" />
                        <TextInput v-model="form.max_limit" type="number" step="any" min="0" :label="t('Max Limit')" :error="form.errors.max_limit" :disabled="!payload.can_edit_limits" />
                        <Field :label="t('Color')" :error="form.errors.color" :hint="t('On the aluminum type')">
                            <input v-model="form.color" :list="colorsListId" :maxlength="50" :class="[controlClasses, 'h-10']" />
                            <datalist :id="colorsListId">
                                <option v-for="color in options.colors" :key="color" :value="color" />
                            </datalist>
                        </Field>
                        <MultiSelect v-model="form.unit_id" single :options="options.units" :label="t('Unit')" />
                        <MultiSelect v-model="form.item_type_profile_id" single :options="options.profiles" :label="t('Item Type')" />
                        <template v-if="isWeight">
                            <TextInput v-model="form.bundle_count" type="number" step="1" min="0" :label="t('Bundle Count')" :error="form.errors.bundle_count" />
                            <SelectInput v-model="form.profile_thickness" :label="t('Profile Thickness')" :options="options.thicknesses" placeholder="" :error="form.errors.profile_thickness" />
                            <SelectInput v-model="form.is_open_profile" :label="t('Profile Shape')" :options="options.profile_shapes" placeholder="" :error="form.errors.is_open_profile" />
                            <TextInput v-model="form.area" type="number" step="any" min="0" :label="t('Area')" :error="form.errors.area" />
                        </template>
                    </div>
                </template>

                <template #images>
                    <ImageUpload v-model="form.image" v-model:removed="removedImages" :existing="payload.image ? [payload.image] : []" :error="form.errors.image" />
                </template>

                <template #notes>
                    <TextareaInput v-model="form.short_description" :rows="4" :error="form.errors.short_description" />
                </template>

                <template #suppliers>
                    <MultiSelect v-model="form.supplier_ids" :options="options.suppliers" :label="t('Item Suppliers')" />
                </template>
            </Tabs>
        </form>

        <template #footer>
            <Button v-if="can('Product Create')" @click="emit('create')"><Plus class="size-4" />{{ t('New') }}</Button>
            <Button @click="open = false"><Returns class="size-4" />{{ t('Back') }}</Button>
            <Button variant="primary" type="submit" form="item-card-form" :loading="form.processing" :disabled="!payload"><Check class="size-4" />{{ t('Save') }}</Button>
        </template>
    </Modal>
</template>
