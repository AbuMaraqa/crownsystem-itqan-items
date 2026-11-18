import type { Option } from '@/Components/form/SelectInput.vue';

/** A category in the tree: its number and name. Its children load when it opens. */
export interface TreeCategory {
    id: number;
    code: string | null;
    name: string;
}

/** An item directly under a category; a main item opens on its pieces. */
export interface TreeProduct {
    id: number;
    sku: string | null;
    name: string | null;
    has_sub_products: boolean;
}

export interface TreePiece {
    id: number;
    sku: string | null;
    name: string | null;
}

/** `products.tree.children`: what opens under a category. */
export interface CategoryChildren {
    categories: TreeCategory[];
    products: TreeProduct[];
    has_more: boolean;
}

/** One row of the node table or of the search results. */
export interface TreeRow {
    key: string;
    kind: 'product' | 'category';
    id: number;
    sku: string | null;
    barcode: string | null;
    name: string | null;
    main_product_id: number | null;
    is_header: boolean;
    is_main: boolean;
    is_sub: boolean;
    is_context: boolean;
    is_inactive: boolean;
    /** Safe HTML from the server's SearchHighlighter (escaped text, matches in <mark>). */
    html: { sku: string; barcode: string; name: string } | null;
    matched_in: string | null;
}

export interface SearchSummary {
    count: number;
    has_more: boolean;
    approximate: boolean;
    corrected_from: string | null;
    corrected_to: string | null;
}

export interface RowsResponse {
    rows: TreeRow[];
    has_more: boolean;
    summary: SearchSummary | null;
}

export type NodeKind = 'root' | 'category' | 'product';

export interface NodeAction {
    key: 'edit' | 'add_category' | 'add_item' | 'add_piece' | 'copy' | 'delete' | 'move' | 'movement' | 'stock_report';
    label: string;
    confirm: string | null;
}

/** `products.tree.actions`: the node's type, its ids and the commands the user may run. */
export interface NodeMenu {
    type: 'root' | 'category' | 'main' | 'piece' | 'item';
    category_id: number | null;
    product_id: number | null;
    code: string | null;
    name: string | null;
    actions: NodeAction[];
}

export interface QuantityRow {
    id: number;
    sku: string | null;
    name: string | null;
    quantity: number;
    weight: number;
    is_total: boolean;
    unit: string | null;
    min_limit_1: number | null;
    min_limit_2: number | null;
    max_limit: number | null;
    is_low: boolean;
    is_sub: boolean;
}

export interface ProfileOption extends Option {
    aluminium: boolean;
}

/** `products.tree.items.create|edit`: the item card. */
export interface ItemCardPayload {
    id: number | null;
    state: Record<string, unknown>;
    image: { id: number; url: string } | null;
    /** The weighted average cost from the purchases; shown, never typed. Absent on a new item. */
    average_cost?: number;
    other_colors: Option[];
    parent: { code: string | null; name: string } | null;
    main_sku: string | null;
    labels: { main_product_id: Option[]; synonym_product_id: Option[] };
    can_edit_limits: boolean;
    options: {
        item_types: Option[];
        pricing_types: Option[];
        service_kinds: Option[];
        cost_sources: Option[];
        revenue_accounts: Option[];
        cost_accounts: Option[];
        units: Option[];
        profiles: ProfileOption[];
        thicknesses: Option[];
        profile_shapes: Option[];
        suppliers: Option[];
        colors: string[];
    };
}

/** What the last save flashed (`tree_saved`), so the page shows it. */
export interface SavedNode {
    kind: 'product' | 'category';
    id: number;
    main_product_id?: number | null;
    path?: number[];
    key: string;
}

/** The card a menu command or a button opens, with its tree context. */
export interface CardRequest {
    productId?: number | null;
    fixedMainId?: number | null;
    categoryId?: number | null;
    /** A new item under `categoryId` only: the chosen main item and row do not apply. */
    underCategory?: boolean;
    tab?: string;
}

/** A category card: a new one under `parent`, or an existing one by id. */
export interface CategoryCardRequest {
    id?: number | null;
    parentId?: number | null;
    parent?: { code: string | null; name: string } | null;
}

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

    if (!response.ok) {
        throw new Error(`${response.status}`);
    }

    return (await response.json()) as T;
}
