import type { Option } from '@/Components/form/SelectInput.vue';

export interface OrderLine {
    id?: number;
    product_id: number | null;
    quantity: number | string;
    /** The item's base price, shown read only; the server prices the line. */
    price: number;
}

export interface OrderForm {
    user_id: number | null;
    branch_id: number | null;
    city_id: number | null;
    district_id: number | null;
    items: OrderLine[];
    delivery_fee: number | string;
    status: number | null;
    payment_method: number | null;
    payment_status: number | null;
    delivery_address: string;
    contact_phone: string;
    notes: string;
}

export interface OrderFormOptions {
    branches: Option[];
    statuses: Option[];
    paymentMethods: Option[];
    paymentStatuses: Option[];
    labels: { customers: Option[]; cities: Option[]; districts: Option[]; products: Option[] };
}
