export interface TableMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/** The shape `Modules\Core\Support\AdminTable::rows()` sends. */
export interface TablePayload<Row> {
    data: Row[];
    meta: TableMeta;
    query: { filter: Record<string, string>; sort: string };
    /** The column rows are dragged by (`AdminTable::reorderable()`), or null. */
    reorder?: string | null;
    /**
     * URL input the server could not use and replaced with its default, by input name
     * (`sort`, `perPage`, `page`, `filter.<key>`): shown above the table (`Modules\Core\Support\TableInput`).
     */
    warnings?: Record<string, string>;
}

export interface TableColumn {
    key: string;
    label: string;
    /** The name the server sorts by; omit when the column cannot be sorted. */
    sort?: string;
    class?: string;
    /** The column can be hidden from the columns menu. Hidden by default when `hidden` is true. */
    toggleable?: boolean;
    hidden?: boolean;
    /** `user`: the cell is a `UserCellData` drawn by `UserCell` (e.g. `createdByColumn()`). */
    type?: 'user';
}

export interface FilterOption {
    value: string | number;
    label: string;
}

/**
 * A table filter. The URL carries it as `filter[key]`; date and number ranges as
 * `filter[key_from]` and `filter[key_to]`.
 */
export type TableFilter =
    | { type?: 'select'; key: string; label: string; options: FilterOption[] }
    | { type: 'boolean'; key: string; label: string; trueLabel?: string; falseLabel?: string }
    | { type: 'text'; key: string; label: string; placeholder?: string }
    | { type: 'date-range'; key: string; label: string }
    | { type: 'number-range'; key: string; label: string };

export interface FilterChip {
    /** URL keys this chip clears. */
    keys: string[];
    label: string;
}
