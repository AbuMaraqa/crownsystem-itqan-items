export interface Metric {
    key: 'users' | 'clients' | 'products' | 'orders' | 'branches';
    title: string;
    value: number;
}

export interface QuickLink {
    key: 'orders' | 'products' | 'add_product' | 'categories' | 'branches' | 'users';
    label: string;
    url: string;
}

export interface MonthlySeries {
    categories: string[];
    data: number[];
}

export interface LabelledSeries {
    labels: string[];
    data: number[];
}

export interface RecentOrder {
    id: number;
    order_number: string;
    customer: string | null;
    total: string;
    status: string | null;
    tone: 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';
    date: string | null;
    url: string | null;
}

export interface RecentOrders {
    url: string | null;
    rows: RecentOrder[];
}

/** Something that needs attention now (DashboardService::alerts). */
export interface Alert {
    key: 'pending_orders' | 'low_stock';
    title: string;
    value: number;
    /** The list that shows them; null while the page is not available. */
    url: string | null;
}

/** A kind of date on the purchase order calendar, with its colors. */
export interface CalendarType {
    key: string;
    label: string;
    color: string;
    tint: string;
}

/** Null without the permission (DashboardService::widgets). */
export interface PurchaseOrderCalendar {
    /** JSON events for a date range. */
    url: string;
    types: CalendarType[];
}

export interface CalendarEvent {
    id: string;
    type: string;
    date: string;
    title: string;
    url: string;
    client: string | null;
}
