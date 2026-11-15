import type { StatusBadge } from '../parts/statusBadge';
import type { Option, UserCellData } from '../parts/types';

/** A sub-item (piece) under its main item, as `PurchaseOrderMainItems::for()` sends it. */
export interface SubItem {
    name: string;
    barcode: string;
    count: number;
    length: number | null;
    weight_per_meter: number | null;
    total_weight: number | null;
}

/** A main item with its sub-items (`PurchaseOrderMainItems::for()`). */
export interface MainItem {
    name: string;
    code: string | null;
    barcode: string | null;
    line_is_main: boolean;
    is_weight_priced: boolean;
    length: number | null;
    quantity: number;
    total_weight: number | null;
    actual_weight: number | null;
    sub_items: SubItem[];
}

/** A line of the add page, before saving (`ConsolidatedPurchaseOrderPickerService::rowFor()`). */
export interface PulledRow {
    source_purchase_order_item_id: number;
    product_id: number;
    description: string | null;
    quantity: number | string;
    unit_price: number | string | null;
    actual_weight: number | string | null;
    total_weight: number | string | null;
    pricing_type: 'simple' | 'weight';
    source_purchase_order_id: number | null;
    purchase_order_reference: string | null;
    pieces: PulledPiece[];
    main_item: MainItem;
    limit: number | null;
}

/** A purchase order line in the picker (`ConsolidatedPurchaseOrderPickerService::mainItems()`). */
export interface PickerItem {
    id: number;
    measure: string;
    remaining: number;
    is_weight_priced: boolean;
    main: MainItem;
    matched: number[];
    picks_sub_items: boolean;
    sub_item_keys: { key: string; added: boolean }[];
    selectable_keys: string[];
    already_added: boolean;
}

/** A line of the items tab (`ConsolidatedPurchaseOrderTabs::items()`). */
export interface ItemRow {
    id: number;
    image: string | null;
    description: string;
    main: MainItem;
    source: { id: number; reference_no: string | null } | null;
    is_weight: boolean;
    quantity: string;
    quantity_value: string;
    original: string | null;
    remaining_text: string | null;
    remaining: number | null;
    total_weight: string | null;
    actual_weight: string | null;
    unit_price: string;
    line_total: string;
}

export interface FileRef {
    path: string;
    name: string;
    url: string;
}

export interface CpoRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    client: string | null;
    currency: string | null;
    total_amount: string;
    created_by: string | null;
    followed_up_by: Option | null;
    currency_id: number | null;
}

export interface TabOptions {
    currencyOptions: Option[];
    shippingTypeOptions: Option[];
    shippingClassificationOptions: Option[];
    shippingTermsOptions: Option[];
    containerClassification: number;
    additionalCostTypeOptions: Option[];
    attachmentCategoryOptions: Option[];
    reappraisalElementOptions: Option[];
    reappraisalStatusOptions: Option[];
    paymentTypeOptions: Option[];
    bankCreditTypeOptions: Option[];
    financialStatusOptions: Option[];
    deliveryCostElements: { id: number; name: string }[];
    clearanceAttachmentTypes: { id: number; name: string }[];
}

export interface OrderItemChoice {
    id: number;
    label: string;
}

/** A record of a tab: what the table shows, plus `form` (the edit values) and its party. */
export interface TabRecord {
    id: number;
    form: Record<string, unknown>;
    party?: Option | null;
    created_by?: UserCellData | null;
    [key: string]: unknown;
}

/** A sub-item of a pulled line, as it was recorded in the purchase order (with its source piece). */
export interface PulledPiece {
    source_purchase_order_item_piece_id: number | null;
    product_id: number;
    barcode: string | null;
    description: string | null;
    piece_count: number | string | null;
    piece_length: number | string | null;
    weight_per_meter: number | string | null;
}
