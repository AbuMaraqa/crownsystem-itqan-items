import type { DocumentRecord, GroupedLine, Option, OrderSummary, WarehouseContext } from '../parts/types';

/** One sales order line (or piece) on the delivery note (DeliveryNoteService::formLines). */
export interface DeliveryLine extends GroupedLine {
    key: string;
    id: number | null;
    sales_order_item_id: number;
    sales_order_item_piece_id: number | null;
    group: string | null;
    piece_length: number;
    ordered: number;
    delivered: number;
    outstanding: number;
    weight_per_unit: number;
    unit_cost: number;
    quantity: number | string | null;
    location_id: number | string | null;
    /** Where a posted line was picked from. */
    picked: { code?: string | null; quantity: number }[];
    notes: string | null;
}

/** The form DeliveryNoteController sends and DeliveryNoteRequest reads. */
export interface DeliveryForm {
    sales_order_id: number | null;
    warehouse_id: number | string | null;
    delivery_date: string;
    driver_name: string | null;
    vehicle_no: string | null;
    receiver_name: string | null;
    delivery_address: string | null;
    notes: string | null;
    items: DeliveryLine[];
}

export interface DeliveryPageProps {
    note: DeliveryForm;
    order: OrderSummary | null;
    warehouseOptions: Option[];
    context: WarehouseContext;
    canSeeCost: boolean;
    record?: DocumentRecord;
}
