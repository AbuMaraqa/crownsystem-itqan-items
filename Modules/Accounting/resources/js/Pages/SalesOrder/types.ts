import type { CustomerMaterial, GridRow, ServiceLine, ServiceOptions } from '../parts/items-grid/types';
import type { StatusBadge } from '../parts/statusBadge';
import type { Option } from '../parts/types';
import type { TaxOption } from '../SalesQuotation/types';

/** The form SalesOrderController sends and SalesOrderRequest reads. */
export interface SalesOrderForm {
    sales_quotation_id: number | string | null;
    client_id: number | string | null;
    branch_id: number | null;
    warehouse_id: number | null;
    fulfillment_type: string;
    direct_supplier_id: number | string | null;
    order_type_id: number | null;
    order_date: string;
    expected_delivery_date: string | null;
    currency_id: number | null;
    exchange_rate: string | null;
    tax_id: number | null;
    order_discount: string | number | null;
    notes: string | null;
    items: GridRow[];
    services: ServiceLine[];
    customer_materials: CustomerMaterial[];
}

export interface SalesOrderOptions {
    warehouseOptions: Option[];
    currencyOptions: Option[];
    taxOptions: TaxOption[];
    fulfillmentOptions: Option[];
    orderTypeOptions: OrderTypeOption[];
    serviceOptions: ServiceOptions;
}

/** A sales order type with its maximum lead time, in days from the order's confirmation. */
export interface OrderTypeOption extends Option {
    lead_days: number;
}

interface Link {
    id: number;
    reference_no: string;
}

/** What the edit page shows around the form (SalesOrderController::edit `record`). */
export interface SalesOrderRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    client: Option | null;
    direct_supplier: Option | null;
    margin_hint: string | null;
    /** Ready to invoice by (d/m/Y): confirmation date + the type's lead time. */
    ready_by: string | null;
    late: boolean;
    editable: boolean;
    fulfillment_locked: boolean;
    can_confirm: boolean;
    can_prepare: boolean;
    can_invoice: boolean;
    can_produce: boolean;
    can_delete: boolean;
    sales_quotation: Link | null;
    sales_invoices: (Link & { status: StatusBadge | null })[];
    production_orders: (Link & { status: string | null; total_cost: string })[];
    direct_supply: {
        supplier: string | null;
        is_draft: boolean;
        notified_at: string | null;
        awaiting: boolean;
        can_create_purchase_order: boolean;
        purchase_orders: (Link & { supplier: string | null; status: string | null })[];
    } | null;
    delivery: {
        can_deliver: boolean;
        is_direct: boolean;
        percent: number;
        notes: (Link & { status: string | null })[];
    };
}

/** The `ItemsGridDocuments` key of these pages. */
export const DOCUMENT = 'sales-order';

export const DIRECT = 'direct';
