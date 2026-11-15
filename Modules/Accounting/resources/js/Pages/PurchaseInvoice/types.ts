import type { GridRow } from '../parts/items-grid/types';
import type { StatusBadge } from '../parts/statusBadge';
import type { Option } from '../parts/types';

export type { PurchaseDocumentOptions } from '../PurchaseQuotation/types';

/** The form PurchaseInvoiceController sends and PurchaseInvoiceRequest reads. */
export interface PurchaseInvoiceForm {
    client_id: number | null;
    purchase_order_id: number | null;
    branch_id: number | null;
    warehouse_id: number | null;
    invoice_date: string;
    due_date: string | null;
    currency_id: number | null;
    exchange_rate: string | null;
    tax_id: number | null;
    invoice_discount: string | number | null;
    notes: string | null;
    items: GridRow[];
}

type Reference = { id: number; reference_no: string };

/** What the edit page shows around the form (PurchaseInvoiceController::edit `record`). */
export interface PurchaseInvoiceRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    client: Option | null;
    editable: boolean;
    items_editable: boolean;
    can_post: boolean;
    can_cancel: boolean;
    can_delete: boolean;
    posted: boolean;
    cancelled: boolean;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    purchase_order: Reference | null;
    purchase_quotation: Reference | null;
    journal_entry: { id: number; entry_number: string } | null;
}

/** The `ItemsGridDocuments` key of this page. */
export const DOCUMENT = 'purchase-invoice';
