import type { StatusBadge } from '../../parts/statusBadge';
import type { Option, UserCellData } from '../../parts/types';

export type { Option, StatusBadge, UserCellData };

/** Every line of an inventory document carries its main-item group (Support\InventoryLines). */
export interface GroupedLine {
    product_id: number;
    barcode: string | null;
    description: string | null;
    /** `main-<id>` when the line sits under a main (weight) item; null for a line of its own. */
    group_key: string | null;
    group_label: string | null;
}

export interface Placement {
    location_id: number;
    code: string;
    quantity: number;
    is_primary: boolean;
    direction: string | null;
}

export interface Levels {
    on_hand: number;
    weight: number;
    reserved: number;
    incoming: number;
    available: number;
}

/** Support\InventoryLines::warehouseContext(): what the lines show about the chosen warehouse. */
export interface WarehouseContext {
    locations: Option[];
    levels: Record<string, Levels>;
    placements: Record<string, Placement[]>;
    primary: Record<string, number>;
}

/** The top of a document page (InventoryDocumentPages::documentRecord). */
export interface DocumentRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    editable: boolean;
    is_draft: boolean;
    is_posted: boolean;
    posted_by: string | null;
    posted_at: string | null;
    created_by: string | null;
    journal_entry: { id: number; number: string } | null;
    total_cost: string | null;
    date: string | null;
}

export interface OrderSummary {
    id: number;
    reference_no: string;
    party: string | null;
    date: string | null;
}

export interface OrderOption extends Option {
    party: string | null;
    date: string | null;
    status: string | null;
}

/** A row of the four document lists (InventoryDocumentPages::documentTable). */
export interface DocumentRow {
    id: number;
    reference_no: string;
    date: string | null;
    total_quantity: string;
    total_weight: string;
    total_cost: string | null;
    status: StatusBadge | null;
    created_by: UserCellData | null;
    editable: boolean;
    can_delete: boolean;
}

export interface DocumentTableOptions {
    statusOptions: Option[];
    warehouseOptions: Option[];
    userOptions: Option[];
    canSeeCost: boolean;
}

export const emptyContext = (): WarehouseContext => ({ locations: [], levels: {}, placements: {}, primary: {} });

/** A number as typed in a cell; empty is zero. */
export function num(value: unknown): number {
    const parsed = Number.parseFloat(String(value ?? ''));

    return Number.isFinite(parsed) ? parsed : 0;
}

/** Quantities as the system prints them: up to 4 decimals, no trailing zeros, Latin digits. */
export function qty(value: unknown, decimals = 4): string {
    const rounded = Math.round(num(value) * 10 ** decimals) / 10 ** decimals;

    return rounded.toLocaleString('en-US', { maximumFractionDigits: decimals });
}
