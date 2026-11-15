import type { GridRow } from '../parts/items-grid/types';
import type { StatusBadge } from '../parts/statusBadge';
import type { Option } from '../parts/types';

/** The form PurchaseOrderController sends and PurchaseOrderRequest reads. */
export interface PurchaseOrderForm {
    client_id: number | null;
    branch_id: number | null;
    warehouse_id: number | null;
    order_date: string;
    expected_delivery_date: string | null;
    delivery_type: string;
    direct_customer_id: number | null;
    direct_sales_order_id: number | null;
    notes: string | null;
    items: GridRow[];
    /** New images; saved ones the user removes or reorders. */
    attachments: File[];
    removed_attachments: (number | string)[];
    attachment_order: (number | string)[];
}

export interface PurchaseOrderOptions {
    warehouseOptions: Option[];
    deliveryTypeOptions: Option[];
    salesOrderOptions: Option[];
}

/** What the edit page shows around the form (PurchaseOrderController::edit `record`). */
export interface PurchaseOrderRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    supplier: string | null;
    order_date: string | null;
    items_count: number;
    total_amount: string;
    editable: boolean;
    locked: boolean;
    delivery_locked: boolean;
    can_confirm: boolean;
    can_create_quotation: boolean;
    can_receive_goods: boolean;
    can_mark_received: boolean;
    can_invoice: boolean;
    can_cancel: boolean;
    can_delete: boolean;
    quotation: { id: number; reference_no: string; status: StatusBadge | null } | null;
    direct: { customer: string | null; sales_order: { id: number; reference_no: string } | null } | null;
    receiving: { percent: number; receipts: { id: number; reference_no: string; status: string | null }[] } | null;
}

/** The `ItemsGridDocuments` key of this page. */
export const DOCUMENT = 'purchase-order';

export const DIRECT = 'direct';
