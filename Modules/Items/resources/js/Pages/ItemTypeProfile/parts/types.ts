import type { Option } from '@/Components/form/SelectInput.vue';

export interface ItemTypeProfileRow {
    id: number;
    code: string;
    name: string;
    sort_order: number;
    /** null: the field is hidden on the document line. */
    quantity_decimals: number | null;
    price_decimals: number | null;
    notes_count: number;
    date_mode: string;
    bonus_mode: string;
    serial_mode: string;
    sub_item_mode: string;
    dimensions: number;
    has_color: boolean;
    has_size: boolean;
    is_active: boolean;
}

/** The choices of each mode, from the enums' `options()`. */
export interface ItemTypeModes {
    date: Option[];
    bonus: Option[];
    serial: Option[];
    subItem: Option[];
}
