import type { ExistingImage } from '@/Components/form/ImageUpload.vue';
import type { Option } from '@/Components/form/SelectInput.vue';

export type ErrorOf = (field: string) => string | undefined;

/** A form number: kept as typed, the server validates it. */
export type Num = number | string | null;

/** What an item and a variation share. */
export interface ProductRowFields {
    name: string;
    slug: string | null;
    short_description: string | null;
    description: string | null;
    sku: string | null;
    barcode: string | null;
    base_price: Num;
    max_limit: Num;
    min_limit_1: Num;
    min_limit_2: Num;
    category_id: (number | string)[];
    /** New files. */
    image: File | File[] | null;
    gallery: File | File[] | null;
    /** Saved images, shown only. */
    images: ExistingImage[];
    gallery_images: ExistingImage[];
    removed_image: (number | string)[];
    removed_gallery: (number | string)[];
}

export interface VariationRow extends ProductRowFields {
    id: number | null;
    attribute_values: number[];
    /** A weight item's colour: its price group (category × colour). The server names the row from it. */
    category_finish_id: number | null;
    is_active: boolean;
}

export interface AllowedOptionRow {
    addon_option_id: number | string | null;
    price: Num;
    is_default: boolean;
}

export interface AddonRow {
    addon_id: number | string | null;
    is_required: boolean;
    min_selection: Num;
    max_selection: Num;
    max_quantity_per_option: Num;
    allowed_options: AllowedOptionRow[];
}

export interface AccessoryRow {
    id?: number | null;
    accessory_product_id: number | string | null;
    quantity: Num;
}

export interface CuttingRecipeRow {
    id?: number | null;
    output_product_id: number | string | null;
    pieces_per_unit: Num;
}

export interface ProductForm extends ProductRowFields {
    weight_per_meter: Num;
    length: Num;
    pricing_type: 'simple' | 'weight';
    item_type_profile_id: number | string | null;
    bundle_count: Num;
    profile_thickness: string | null;
    is_open_profile: number | string | null;
    area: Num;
    item_type: string;
    service_kind: string | null;
    service_cost_source: string | null;
    revenue_account_id: number | string | null;
    cost_account_id: number | string | null;
    site_area: Num;
    brand_id: number | string | null;
    unit_id: number | string | null;
    tags: string[];
    labels: string[];
    manufacturers: (number | string)[];
    branches: (number | string)[];
    addons: AddonRow[];
    accessories: AccessoryRow[];
    cutting_recipes: CuttingRecipeRow[];
    variations: VariationRow[];
    /** attribute id => chosen value ids. Browser only: it builds the rows. */
    attributes: Record<string, number[]>;
}

export interface ProfileOption extends Option {
    aluminium: boolean;
}

export interface AttributeChoice {
    id: number;
    name: string;
    values: { id: number; name: string; color: string | null }[];
}

export interface AddonChoice {
    id: number;
    name: string;
    options: { id: number; name: string; price: number | string }[];
}

/** The lists the form chooses from; the same on Add and Edit. */
export interface ProductFormOptions {
    categories: Option[];
    brands: Option[];
    units: Option[];
    manufacturers: Option[];
    branches: Option[];
    tagSuggestions: string[];
    labelSuggestions: string[];
    itemTypeProfiles: ProfileOption[];
    itemTypes: Option[];
    profileThicknesses: Option[];
    serviceKinds: Option[];
    serviceCostSources: Option[];
    revenueAccounts: Option[];
    costAccounts: Option[];
    attributes: AttributeChoice[];
    addons: AddonChoice[];
    canEditLimits: boolean;
}

/** A picker option from `products.lookup`; `length` lets a cutting recipe count its pieces. */
export interface LookupOption extends Option {
    length?: string | number | null;
}

/** A weight item's colour to choose: a price group of its categories, from `products.colours` (CategoryColours). */
export interface ColourChoice {
    id: number;
    finish_id: number;
    name: string;
    category: string;
    swatch: string | null;
    is_active: boolean;
    /** price list id => kg sell price */
    prices: Record<string, string | null> | never[];
}

export interface ColourCatalogue {
    lists: { id: number; name: string }[];
    colours: ColourChoice[];
}

/** One colour's stock, by variation id (ProductStockSummary::variants). */
export interface VariantStock {
    on_hand: string;
    available: string;
    available_negative: boolean;
    weight: string | null;
}

export interface MadeFromRow {
    label: string;
    yield: string;
}

export type BadgeTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';

export interface StockSummary {
    is_main: boolean;
    levels: { on_hand: string; reserved: string; incoming: string; available: string; available_negative: boolean; weight: string | null };
    status: { label: string; tone: BadgeTone };
    value: string | null;
    cost: { average: string; per_kg: string | null; value: string } | null;
    warehouses: { name: string | null; location: string | null; quantity: string; weight: string | null }[];
}

/** What the Edit page knows beyond the form: saved labels of server-searched pickers, lists shown only. */
export interface ProductContext {
    productId: number | null;
    /** The picker route: `products.lookup` on add, `products.edit-lookup` on edit. */
    lookupRoute: string;
    labels: Record<string, string>;
    madeFrom: MadeFromRow[];
    stock: StockSummary | null;
    stockLinks: { label: string; href: string }[];
    variantStock: Record<string, VariantStock>;
}
