import type { AllocationPanelData } from '../parts/PaymentAllocationPanel.vue';
import type { GridRow, ServiceLine, ServiceOptions } from '../parts/items-grid/types';
import type { StatusBadge } from '../parts/statusBadge';
import type { Option } from '../parts/types';
import type { TaxOption } from '../SalesQuotation/types';

/** The form SalesInvoiceController sends and SalesInvoiceRequest reads. */
export interface SalesInvoiceForm {
    sales_order_id: number | string | null;
    client_id: number | string | null;
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
    services: ServiceLine[];
}

export interface SalesInvoiceOptions {
    warehouseOptions: Option[];
    currencyOptions: Option[];
    taxOptions: TaxOption[];
    serviceOptions: ServiceOptions;
}

/** What the edit page shows around the form (SalesInvoiceController::edit `record`). */
export interface SalesInvoiceRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    client: Option | null;
    margin_hint: string | null;
    invoice_date: string | null;
    editable: boolean;
    from_order: boolean;
    sales_order: { id: number; reference_no: string } | null;
    materials_weight: number;
    can_post: boolean;
    can_cancel: boolean;
    can_delete: boolean;
    cancelled: boolean;
    cancelled_at: string | null;
    cancellation_reason: string | null;
}

export type { AllocationPanelData };

/** The `ItemsGridDocuments` key of these pages. */
export const DOCUMENT = 'sales-invoice';
