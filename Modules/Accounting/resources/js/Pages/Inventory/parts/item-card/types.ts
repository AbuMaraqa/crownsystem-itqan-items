import type { StatusBadge } from '../types';

/** Item card data, formatted by the server (Modules\Accounting\Support\ItemCardData). */
export interface ItemLevels {
    on_hand: string;
    reserved: string;
    incoming: string;
    available: string;
    available_negative: boolean;
    weight: string | null;
}

/** An open sales order (reserves the item) or purchase order (brings it in), with its outstanding quantity. */
export interface OpenOrder {
    order_id: number;
    reference: string | null;
    party: string | null;
    date: string | null;
    expected: string | null;
    quantity: string;
}

/** ItemCardData::product() */
export interface ItemCardProduct {
    id: number;
    name: string;
    image: string | null;
    price_group: string | null;
    barcode: string | null;
    sku: string | null;
    weight_per_meter: string | null;
    length: string | null;
    shelf: string | null;
    max_limit: string;
    min_limit_1: string;
    min_limit_2: string;
}

/** ItemCardData::summary() */
export interface ItemCardSummary {
    levels: ItemLevels;
    status: StatusBadge;
    value: string | null;
    cost: { average: string; per_kg: string | null; value: string } | null;
    main: { sub_items: number; on_hand: string; weight: string; reserved: string; incoming: string; available: string; value: string | null } | null;
    warehouses: { id: number; name: string | null; quantity: string; weight: string | null }[];
    sales_orders: OpenOrder[];
    purchase_orders: OpenOrder[];
}
