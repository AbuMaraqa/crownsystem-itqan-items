/**
 * Which tab shows each field, so a validation error can mark its tab and open it. Fields of the
 * general card and the side cards are always in view and belong to no tab.
 */
const GROUPS: Record<string, readonly string[]> = {
    general: [
        'sku', 'barcode', 'weight_per_meter', 'length', 'pricing_type', 'item_type_profile_id', 'bundle_count', 'profile_thickness',
        'is_open_profile', 'area', 'item_type', 'service_kind', 'service_cost_source', 'revenue_account_id', 'cost_account_id',
        'main_product_id', 'site_area',
    ],
    price: ['base_price'],
    inventory: ['color', 'max_limit', 'min_limit_1', 'min_limit_2'],
    accessories: ['accessories'],
    addons: ['addons'],
    cutting_recipes: ['cutting_recipes'],
    variations: ['variations', 'attributes'],
    images: ['image', 'gallery', 'removed_image', 'removed_gallery'],
    categories: ['category_id'],
    seo: ['meta_title', 'meta_description', 'meta_keywords'],
};

/** Error keys that belong to a tab; the first segment of `variations.3.base_price` decides. */
export function tabsWithError(keys: string[]): Set<string> {
    const tabs = new Set<string>();

    for (const key of keys) {
        for (const [tab, fields] of Object.entries(GROUPS)) {
            if (fields.includes(key.split('.')[0])) tabs.add(tab);
        }
    }

    return tabs;
}

/** The tabs of a variation row holding an error, from its keys without the `variations.3.` prefix. */
export function rowTabsWithError(errors: Record<string, string | undefined>, prefix: string): Set<string> {
    return tabsWithError(
        Object.keys(errors)
            .filter((key) => key.startsWith(prefix) && errors[key])
            .map((key) => key.slice(prefix.length)),
    );
}
