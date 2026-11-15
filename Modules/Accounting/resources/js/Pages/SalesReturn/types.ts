import type { AllocationPanelData } from '../parts/PaymentAllocationPanel.vue';
import type { GridRow } from '../parts/items-grid/types';
import type { StatusBadge } from '../parts/statusBadge';

/** The new return (SalesReturnController::add): it takes everything still returnable of the invoice. */
export interface SalesReturnCreateForm {
    client_id: number | string | null;
    sales_invoice_id: number | string | null;
    warehouse_id: number | null;
    return_date: string;
    notes: string | null;
}

/** What is saved on the return page: the date, warehouse, notes and what returns of each line. */
export interface SalesReturnForm {
    warehouse_id: number | null;
    return_date: string;
    notes: string | null;
    items: GridRow[];
}

/** What the edit page shows around the form (SalesReturnController::edit `record`). */
export interface SalesReturnRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    client: string | null;
    sales_invoice: { id: number; reference_no: string } | null;
    currency: string | null;
    exchange_rate: string;
    tax_rate: string;
    invoice_discount: string;
    editable: boolean;
    can_post: boolean;
    can_cancel: boolean;
    can_delete: boolean;
    cancelled: boolean;
    cancelled_at: string | null;
    cancellation_reason: string | null;
}

export type { AllocationPanelData };

/** The `ItemsGridDocuments` key of these pages. */
export const DOCUMENT = 'sales-return';
