/**
 * Which tab shows each field, so a validation error can mark its tab and open it. Fields of the
 * information card above the tabs are always in view and belong to no tab.
 */
const GROUPS: Record<string, readonly string[]> = {
    general: [
        'weight_per_meter', 'length', 'pricing_type', 'item_type_profile_id', 'bundle_count', 'profile_thickness',
        'is_open_profile', 'area', 'item_type', 'service_kind', 'service_cost_source', 'revenue_account_id', 'cost_account_id', 'site_area',
    ],
    variations: ['variations', 'attributes'],
    price: ['base_price'],
    inventory: ['max_limit', 'min_limit_1', 'min_limit_2'],
    accessories: ['accessories'],
    cutting_recipes: ['cutting_recipes'],
    store: ['slug', 'brand_id', 'tags', 'labels', 'manufacturers', 'short_description', 'description', 'addons', 'image', 'gallery', 'removed_image', 'removed_gallery'],
};

/** The tabs inside one variation row. */
const ROW_GROUPS: Record<string, readonly string[]> = {
    general: ['sku', 'barcode', 'is_active'],
    price: ['base_price'],
    inventory: ['max_limit', 'min_limit_1', 'min_limit_2'],
    images: ['image', 'gallery', 'removed_image', 'removed_gallery'],
    categories: ['category_id'],
};

/** Error keys that belong to a tab; the first segment of `variations.3.base_price` decides. */
export function tabsWithError(keys: string[], groups: Record<string, readonly string[]> = GROUPS): Set<string> {
    const tabs = new Set<string>();

    for (const key of keys) {
        for (const [tab, fields] of Object.entries(groups)) {
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
        ROW_GROUPS,
    );
}
