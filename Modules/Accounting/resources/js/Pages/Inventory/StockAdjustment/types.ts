import type { DocumentRecord, GroupedLine, Option, WarehouseContext } from '../parts/types';

/** One item of an opening balance, count or adjustment. */
export interface AdjustmentLine extends GroupedLine {
    id?: number | null;
    location_id: number | string | null;
    location_code?: string | null;
    /** Adjustment and opening: the quantity (an adjustment may be negative). A posted count: the difference. */
    quantity: number | string | null;
    /** Count: what was counted. */
    counted_quantity: number | string | null;
    /** The system balance when the line was added, or at posting. */
    system_quantity: number | string | null;
    unit_cost: number | string | null;
    notes?: string | null;
}

export type AdjustmentType = 'opening' | 'count' | 'adjustment';

/** The form StockAdjustmentController sends and StockAdjustmentRequest reads. */
export interface AdjustmentForm {
    type: AdjustmentType;
    warehouse_id: number | string | null;
    adjustment_date: string;
    reason: string | null;
    notes: string | null;
    items: AdjustmentLine[];
}

export interface AdjustmentPageProps {
    adjustment: AdjustmentForm;
    warehouseOptions: Option[];
    typeOptions: Option[];
    context: WarehouseContext;
    canSeeCost: boolean;
    record?: DocumentRecord & { warehouse: string | null; type_label: string };
}
