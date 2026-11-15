import type { DocumentRecord, GroupedLine, Option, OrderSummary, WarehouseContext } from '../parts/types';

/** One purchase order line (or piece) on the receipt (GoodsReceiptService::formLines). */
export interface ReceiptLine extends GroupedLine {
    key: string;
    id: number | null;
    purchase_order_item_id: number;
    purchase_order_item_piece_id: number | null;
    group: string | null;
    piece_length: number;
    ordered: number;
    received: number;
    outstanding: number;
    weight_per_unit: number;
    unit_cost: number;
    quantity: number | string | null;
    location_id: number | string | null;
    location_code: string | null;
    notes: string | null;
}

/** The form GoodsReceiptController sends and GoodsReceiptRequest reads. */
export interface ReceiptForm {
    purchase_order_id: number | null;
    warehouse_id: number | string | null;
    receipt_date: string;
    supplier_document_no: string | null;
    container_no: string | null;
    received_by_name: string | null;
    notes: string | null;
    items: ReceiptLine[];
}

export interface ReceiptPageProps {
    receipt: ReceiptForm;
    order: OrderSummary | null;
    value: string | null;
    warehouseOptions: Option[];
    context: WarehouseContext;
    canSeeCost: boolean;
    record?: DocumentRecord;
}
