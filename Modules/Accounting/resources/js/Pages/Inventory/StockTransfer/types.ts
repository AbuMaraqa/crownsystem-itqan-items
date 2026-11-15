import type { DocumentRecord, GroupedLine, Option, WarehouseContext } from '../parts/types';

/** One item of the transfer (StockTransferController: the saved lines, or `line` for a new one). */
export interface TransferLine extends GroupedLine {
    id: number | null;
    quantity: number | string | null;
    from_location_id: number | string | null;
    to_location_id: number | string | null;
    from_code?: string | null;
    to_code?: string | null;
    notes: string | null;
    total_cost?: string | null;
}

/** The form StockTransferController sends and StockTransferRequest reads. */
export interface TransferForm {
    from_warehouse_id: number | string | null;
    to_warehouse_id: number | string | null;
    transfer_date: string;
    notes: string | null;
    items: TransferLine[];
}

export interface TransferPageProps {
    transfer: TransferForm;
    warehouseOptions: Option[];
    fromContext: WarehouseContext;
    toContext: WarehouseContext;
    canSeeCost: boolean;
    record?: DocumentRecord & { from_warehouse: string | null; to_warehouse: string | null };
}
