/** A node of the categories tree. `children` is filled when searching; otherwise it loads on open. */
export interface CategoryNode {
    id: number;
    name: string;
    slug: string;
    image: string | null;
    is_active: boolean;
    products_count: number;
    has_children: boolean;
    children: CategoryNode[];
}

export interface CategoryProduct {
    id: number;
    name: string | null;
    sku: string | null;
    barcode: string | null;
}

/** `categories.tree.children`: the sub-categories and the first items of an open category. */
export interface CategoryBranch {
    children: CategoryNode[];
    products: CategoryProduct[];
    limit: number;
}
