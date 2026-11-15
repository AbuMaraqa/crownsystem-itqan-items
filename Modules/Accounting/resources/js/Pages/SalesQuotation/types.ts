import type { StatusBadge } from '../parts/statusBadge';
import type { GridRow } from '../parts/items-grid/types';
import type { Option } from '../parts/types';

/** The form SalesQuotationController sends and SalesQuotationRequest reads. */
export interface SalesQuotationForm {
    client_id: number | null;
    branch_id: number | null;
    warehouse_id: number | null;
    quotation_date: string;
    valid_until: string | null;
    currency_id: number | null;
    exchange_rate: string | null;
    tax_id: number | null;
    quotation_discount: string | number | null;
    notes: string | null;
    items: GridRow[];
}

export interface TaxOption extends Option {
    rate: string;
}

export interface SalesQuotationOptions {
    warehouseOptions: Option[];
    currencyOptions: Option[];
    taxOptions: TaxOption[];
}

/** What the edit page shows around the form (SalesQuotationController::edit `record`). */
export interface SalesQuotationRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    client: Option | null;
    margin_hint: string | null;
    editable: boolean;
    can_convert: boolean;
    can_delete: boolean;
    sales_order: { id: number; reference_no: string } | null;
    totals: { sub_total: string; items_discount: string; tax_amount: string; total_amount: string };
}

/** The `ItemsGridDocuments` key of this page. */
export const DOCUMENT = 'sales-quotation';
