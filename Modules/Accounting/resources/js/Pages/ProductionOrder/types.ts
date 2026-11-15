import type { StatusBadge } from '../parts/statusBadge';
import type { Option } from '../parts/types';

export interface MaterialLine {
    sales_order_item_id: number | null;
    product_id: number | null;
    product_label: string | null;
    description: string | null;
    pricing_type: 'simple' | 'weight';
    quantity: string;
    weight: string;
    unit_cost: string;
}

export interface LaborLine {
    worker_id: number | null;
    worker_label: string | null;
    worker_name: string | null;
    description: string | null;
    rate_basis: string;
    quantity: string;
    rate: string;
    work_date: string | null;
}

export interface ServiceLine {
    product_id: number | null;
    product_label: string | null;
    description: string | null;
    cost_source: string;
    service_provider_id: number | null;
    provider_label: string | null;
    provider_reference: string | null;
    pricing_basis: string;
    quantity: string;
    unit_cost: string;
    sent_date: string | null;
    received_date: string | null;
    notes: string | null;
}

export interface ProductionOrderForm {
    sales_order_id: number | null;
    order_date: string | null;
    due_date: string | null;
    warehouse_id: number | null;
    currency_id: number | null;
    produced_quantity: string;
    notes: string | null;
    materials: MaterialLine[];
    labor: LaborLine[];
    services: ServiceLine[];
}

/** What the order takes from its sales order: the label, the customer and the sales value. */
export interface ProductionContext {
    sales_order: Option | null;
    client_label: string | null;
    sales_amount: number;
}

export interface UnitOption extends Option {
    unit: string;
}

export interface ProductionOptions {
    warehouseOptions: Option[];
    currencyOptions: Option[];
    laborBasisOptions: UnitOption[];
    costSourceOptions: Option[];
    pricingBasisOptions: UnitOption[];
}

export interface ProductionRecord {
    id: number;
    reference_no: string;
    status: StatusBadge | null;
    editable: boolean;
    can_start: boolean;
    can_complete: boolean;
    can_reopen: boolean;
    can_cancel: boolean;
    can_delete: boolean;
    sales_order: { id: number; reference_no: string } | null;
}

export interface CostSummary {
    materials_cost: number;
    labor_cost: number;
    services_cost: number;
    total_cost: number;
    unit_cost: number;
    sales_amount: number;
    margin: number;
    margin_rate: number | null;
}

export interface CostPreview {
    summary: CostSummary;
    materials_weight: number;
    lines: { materials: number[]; labor: number[]; services: number[] };
}

export const blankMaterial = (): MaterialLine => ({
    sales_order_item_id: null,
    product_id: null,
    product_label: null,
    description: null,
    pricing_type: 'simple',
    quantity: '1',
    weight: '0',
    unit_cost: '0',
});

export const blankLabor = (): LaborLine => ({
    worker_id: null,
    worker_label: null,
    worker_name: null,
    description: null,
    rate_basis: 'hour',
    quantity: '1',
    rate: '0',
    work_date: null,
});

export const blankService = (): ServiceLine => ({
    product_id: null,
    product_label: null,
    description: null,
    cost_source: 'internal',
    service_provider_id: null,
    provider_label: null,
    provider_reference: null,
    pricing_basis: 'quantity',
    quantity: '1',
    unit_cost: '0',
    sent_date: null,
    received_date: null,
    notes: null,
});
