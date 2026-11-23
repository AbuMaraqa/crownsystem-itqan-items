/**
 * The shared document items grid (ItemsGrid.vue): rows, their sub-items (pieces) and what the
 * server endpoints answer (Modules\Accounting\Http\Controllers\Admin\ItemsGridController).
 * Row fields are the ones the saving services read (SalesQuotationService::normalizeItems,
 * WeightPieces::normalize); `_key`, `collapsed`, `product_search` and the labels are UI only.
 */

import type { Tone } from '@/lib/tones';

import type { ItemCardProduct, ItemCardSummary } from '../../Inventory/parts/item-card/types';

export type PricingType = 'simple' | 'weight' | string;

export interface GridPiece {
    _key: string;
    id?: number | null;
    product_id: number | null;
    product_label: string;
    source_product_id: number | null;
    source_product_label: string;
    barcode?: string | null;
    description?: string | null;
    piece_count: number | string;
    piece_length: number | string;
    weight_per_meter: number | string;
    total_weight: number | string;
    image_url?: string | null;
    /** Returns: the most pieces that can still be returned. */
    max_piece_count?: number | string | null;

}

export interface GridRow {
    _key: string;
    id?: number | null;
    /** `item` or a group header (`category`) whose items follow it. */
    row_type: 'item' | 'category';
    category_id: number | null;
    category_label: string;
    /** A price-group line («التصنيف — اللون»): no product; its pieces are that colour's profiles. */
    category_finish_id: number | null;
    product_id: number | null;
    product_label: string;
    product_search: string;
    pricing_type: PricingType;
    has_sub_products: boolean;
    barcode?: string | null;
    description?: string | null;
    quantity: number | string | null;
    length?: number | string | null;
    actual_weight: number | string | null;
    unit_price: number | string;
    discount_mode: 'rate' | 'amount';
    discount_rate: number | string;
    discount_amount: number | string;
    line_total: number | string;
    total_weight: number | string;
    /** Returns: billed kg per theoretical kg of the invoice line. 0 elsewhere. */
    weight_factor?: number | string;
    /** Returns: the most that can still be returned. */
    max_quantity?: number | string | null;
    collapsed: boolean;
    pieces: GridPiece[];
    notes?: string | null;

}

/** What a document page tells the grid. Only `document` and `editable` are required. */
export interface GridConfig {
    /** The `Modules\Accounting\Support\ItemsGridDocuments` key: `sales-quotation`, `sales-order`… */
    document: string;
    /** Items can be added, changed and removed. */
    editable: boolean;
    /** Prices and discounts can be typed (defaults to `editable`): an invoice from an order locks items, not prices. */
    pricesEditable?: boolean;
    /** The scale weight can be typed (defaults to `editable`). */
    actualWeightEditable?: boolean;
    /** Quantities and piece counts can be typed (defaults to `editable`): returns lock everything else. */
    quantitiesEditable?: boolean;
    /** The heading of the max-quantity column (returns), or nothing to hide it. */
    quantityLimitLabel?: string | null;
    /** Price and total columns (default true). Purchase orders hide them. */
    showPrices?: boolean;
    /** Line discount rate and amount columns (invoices, quotations). */
    showDiscount?: boolean;
    /** The item length column (purchase orders). */
    showLength?: boolean;
    /** The barcode entry above the grid (defaults to `editable`). */
    barcode?: boolean;
    /** The old "Category" tab (a plain category header). Default false: price groups come from the one search. */
    groups?: boolean;
}

/** The document fields the grid reads for prices, stock and totals. */
export interface GridContext {
    partyId: number | string | null;
    exchangeRate: number | string | null;
    warehouseId?: number | string | null;
    documentDiscount?: number | string | null;
    taxRate?: number | string | null;
    /**
     * Lines outside the grid that count in the document totals (the service lines of a sales order
     * or invoice, ServiceLines.vue): quantity × price less their discount. Optional; pages without
     * them leave it out and nothing changes.
     */
    extraLines?: ExtraLine[];
}

/** A quantity-priced line outside the grid (Support\ItemsGridTotals `extra_lines`). */
export interface ExtraLine {
    _key?: string;
    product_id: number | string | null;
    quantity: number | string | null;
    unit_price: number | string | null;
    discount_mode?: 'rate' | 'amount';
    discount_rate?: number | string | null;
    discount_amount?: number | string | null;
}

/**
 * A service line of a sales order or invoice (ServiceLines.vue), saved by the document's service
 * as a document line of type "service" (Support\SalesServiceLine). The source key
 * (`sales_quotation_item_id`, `sales_order_item_id`) links it to the line it came from.
 */
export interface ServiceLine extends ExtraLine {
    _key: string;
    id?: number | null;
    sales_quotation_item_id?: number | null;
    sales_order_item_id?: number | null;
    product_label: string;
    description: string | null;
    pricing_type?: 'simple';
    pricing_basis: string;
    discount_mode: 'rate' | 'amount';
    discount_rate: number | string;
    discount_amount: number | string;
    line_total: number | string;
    cost_source: string;
    service_provider_id: number | string | null;
    service_provider_label?: string | null;
    unit_cost: number | string;
    notes?: string | null;
}

/** Support\SalesServiceLine::options(). */
export interface ServiceOptions {
    pricing_bases: { value: string; label: string; unit: string }[];
    cost_sources: { value: string; label: string }[];
}

/** Support\SalesServiceLine::defaults(): what a chosen service brings to its line. */
export interface ServicePayload {
    id: number;
    label: string;
    description: string | null;
    pricing_basis: string;
    unit_price: string;
    unit_cost: string;
    cost_source: string;
}

/**
 * A material the customer brought to receive a service on it (CustomerMaterials.vue): no value,
 * no stock; its weight suggests the quantity of a per-kg service.
 */
export interface CustomerMaterial {
    _key: string;
    product_id: number | null;
    product_label: string;
    description: string | null;
    quantity: number | string | null;
    weight: number | string | null;
    received_date: string | null;
    notes: string | null;
}

export interface SearchResult {
    id: number;
    label: string;
    /** `group`: a price group (category × colour), the id is the group's. */
    kind?: 'item' | 'group';
    main_label?: string | null;
    /** A colour variation: the group line it goes into. */
    group_label?: string | null;
    barcode?: string | null;
}

/** Support\ItemsGrid::groupPayload(): a price group and its kg price for the party. */
export interface GroupPayload {
    id: number;
    label: string;
    unit_price: string;
    pricing_type: PricingType;
}

export interface MainProductPayload {
    id: number;
    label: string;
    barcode: string | null;
    description: string | null;
    unit_price: string;
    pricing_type: PricingType;
}

/** Support\ItemsGrid::productPayload(). */
export interface ProductPayload {
    id: number;
    label: string;
    barcode: string | null;
    description: string | null;
    unit_price: string;
    pricing_type: PricingType;
    length: string | null;
    weight_per_meter: string | null;
    image: string | null;
    piece_count: string | null;
    has_sub_products: boolean;
    main_product: MainProductPayload | null;
    /** A colour variation goes inside the line of its price group. */
    group: GroupPayload | null;
    accessories?: AccessoryPayload[];
}

export interface AccessoryPayload {
    quantity: string;
    product: ProductPayload;
}

export interface BarcodeMatch {
    id: number;
    label: string;
    main_label: string | null;
    pack_quantity: string;
}

export interface BarcodeResult {
    matches: BarcodeMatch[];
    add_url: string | null;
}

export interface MatchPiecesResult {
    matched: { source_id: number; product: ProductPayload }[];
    unmatched: string[];
}

/** Support\ItemsGrid::itemStock(). */
/** The item details window (`items-grid.details`): ItemCardData::product() + ItemCardData::summary() over all warehouses. */
export interface ItemDetails {
    product: ItemCardProduct;
    card: ItemCardSummary;
}

export interface StockInfo {
    product_id: number;
    label: string | null;
    here: string | null;
    all: string;
    /** Set when the total is at or below a minimum limit, or out of stock: its color and status label. */
    tone: Tone | null;
    status: string | null;
    unit: string;
    cost: string | null;
}

export interface LineNotice {
    type: 'actual_weight_empty' | 'weight_variance' | string;
    message: string;
    hint?: string;
}

/** One line of Support\ItemsGridTotals::compute(). */
export interface ServerLine {
    key: string;
    pricing_type: PricingType;
    total_weight: number;
    pricing_quantity: number;
    gross: number;
    discount_mode: 'rate' | 'amount';
    discount_rate: number;
    discount_amount: number;
    line_total: number;
    pieces: { key: string; total_weight: number }[];
    notices: LineNotice[];
}

export interface DocumentTotals {
    sub_total: number;
    items_discount: number;
    document_discount: number;
    tax_amount: number;
    total_amount: number;
}

export interface ServerTotals {
    lines: ServerLine[];
    totals: DocumentTotals;
    /** Only when the request sent `extra_lines`. */
    extra_lines?: { key: string; gross: number; discount_amount: number; line_total: number }[];
}

export interface Suggestion {
    product: ProductPayload;
    quantity: string;
    added: boolean;
}

/** A weight line whose main item changed: its pieces wait for the user's choice. */
export interface PieceChoice {
    pieces: GridPiece[];
    unmatched: string[];
    decided: boolean;
}
