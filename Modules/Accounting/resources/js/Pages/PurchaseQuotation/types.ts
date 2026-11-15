import type { GridRow } from '../parts/items-grid/types';
import type { StatusBadge } from '../parts/statusBadge';
import type { Option } from '../parts/types';

/** The form PurchaseQuotationController sends and PurchaseQuotationRequest reads. */
export interface PurchaseQuotationForm {
    purchase_order_id: number | null;
    quotation_date: string;
    valid_until: string | null;
    supplier_reference: string | null;
    currency_id: number | null;
    exchange_rate: string | null;
    tax_id: number | null;
    quotation_discount: string | number | null;
    notes: string | null;
    /** The purchase order's lines (`purchase_order_item_id`) with the supplier's price and discount. */
    items: GridRow[];
}

export interface TaxOption extends Option {
    rate: string;
}

export interface PurchaseDocumentOptions {
    warehouseOptions: Option[];
    currencyOptions: Option[];
    taxOptions: TaxOption[];
}

/** The purchase order a quotation prices. */
export interface QuotedOrder {
    id: number;
    reference_no: string;
    supplier: string | null;
    date: string | null;
    status: StatusBadge | null;
}

/** What the edit page shows around the form (PurchaseQuotationController::edit `record`). */
export interface PurchaseQuotationRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    editable: boolean;
    can_approve: boolean;
    can_invoice: boolean;
    can_cancel: boolean;
    can_delete: boolean;
    approved_by: string | null;
    approved_at: string | null;
    cancelled_at: string | null;
    invoice: { id: number; reference_no: string } | null;
}

/** The `ItemsGridDocuments` key of this page. */
export const DOCUMENT = 'purchase-quotation';
