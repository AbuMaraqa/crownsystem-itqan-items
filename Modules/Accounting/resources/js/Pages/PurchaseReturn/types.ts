import type { GridRow } from '../parts/items-grid/types';
import type { StatusBadge } from '../parts/statusBadge';

/** The add page's form (PurchaseReturnRequest on store): the lines come from the invoice. */
export interface PurchaseReturnAddForm {
    client_id: number | null;
    purchase_invoice_id: number | null;
    warehouse_id: number | null;
    return_date: string;
    notes: string | null;
}

/** The edit page's form: what is returned of each invoice line. */
export interface PurchaseReturnForm {
    warehouse_id: number | null;
    return_date: string;
    notes: string | null;
    items: GridRow[];
}

/** PurchaseReturnService::draftFromInvoice(). */
export interface InvoiceDraft {
    purchase_invoice_id: number;
    client_id: number;
    warehouse_id: number | null;
    currency_id: number | null;
    tax_id: number | null;
    tax_rate: string;
    exchange_rate: string | null;
    totals: { sub_total: string; items_discount: string; invoice_discount: string; tax_amount: string; total_amount: string };
}

export interface PurchaseReturnRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    party: string | null;
    currency: string | null;
    exchange_rate: string;
    tax_rate: string;
    editable: boolean;
    can_post: boolean;
    can_cancel: boolean;
    can_delete: boolean;
    cancelled: boolean;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    invoice: { id: number; reference_no: string } | null;
    journal_entry: { id: number; entry_number: string } | null;
    totals: { sub_total: string; items_discount: string; invoice_discount: string; tax_amount: string; total_amount: string };
}

/** The `ItemsGridDocuments` key of this page. */
export const DOCUMENT = 'purchase-return';
