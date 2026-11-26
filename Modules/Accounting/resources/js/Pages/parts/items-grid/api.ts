import { route } from '@/lib/route';

import type {
    BarcodeResult,
    GridContext,
    GroupPayload,
    ItemDetails,
    ProductPayload,
    SearchResult,
    ServerTotals,
    ServicePayload,
    StockInfo,
} from './types';

type Params = Record<string, unknown>;

/** The Laravel XSRF cookie, sent back as a header on a JSON POST (Inertia's own requests do the same). */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal });

    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    return (await response.json()) as T;
}

async function postJson<T>(url: string, body: unknown, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
        signal,
    });

    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    return (await response.json()) as T;
}

/** A refused form (HTTP 422): the first message of each field. */
export class FormErrors extends Error {
    constructor(public readonly errors: Record<string, string>) {
        super(Object.values(errors)[0] ?? 'Invalid');
    }
}

/** A JSON POST whose validation errors come back as FormErrors. */
async function sendJson<T>(url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    if (response.status === 422) {
        // The app renders JSON validation errors under `data` (bootstrap/app.php); Laravel under `errors`.
        const payload = (await response.json()) as { errors?: Record<string, string[]>; data?: Record<string, string[]> };

        throw new FormErrors(Object.fromEntries(Object.entries(payload.errors ?? payload.data ?? {}).map(([key, messages]) => [key, messages[0] ?? ''])));
    }

    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    return (await response.json()) as T;
}

/**
 * The items grid endpoints (`accounting.items-grid.*`) of one document. Prices are for the
 * document's party and exchange rate at the moment of the call.
 */
export function createGridApi(document: string, context: () => GridContext) {
    const url = (name: string, params: Params = {}): string => route(`accounting.items-grid.${name}`, { document, ...params });
    const priced = (params: Params = {}): Params => {
        const { partyId, exchangeRate } = context();

        return { ...params, party_id: partyId || undefined, exchange_rate: exchangeRate || undefined };
    };

    return {
        searchProducts: (q: string, categoryId: number | null, signal?: AbortSignal) =>
            getJson<SearchResult[]>(url('products', { q, category_id: categoryId ?? undefined }), signal),
        searchCategories: (q: string, signal?: AbortSignal) => getJson<SearchResult[]>(url('categories', { q }), signal),
        searchPieceOutputs: (q: string, sourceProductId: number | null, signal?: AbortSignal, groupId: number | null = null) =>
            getJson<SearchResult[]>(
                url('piece-outputs', { q, source_product_id: sourceProductId ?? undefined, group_id: groupId ?? undefined }),
                signal,
            ),
        group: (groupId: number) => getJson<GroupPayload | null>(url('group', priced({ group_id: groupId }))),
        groupPrices: (ids: number[]) => getJson<Record<string, string>>(url('group-prices', priced({ ids }))),
        item: (productId: number, sourceProductId: number | null = null) =>
            getJson<ProductPayload | null>(url('item', priced({ product_id: productId, source_product_id: sourceProductId ?? undefined }))),
        barcode: (code: string) => getJson<BarcodeResult>(url('barcode', { code })),
        stock: (productId: number, warehouseId: number | string | null | undefined) =>
            getJson<StockInfo | null>(url('stock', { product_id: productId, warehouse_id: warehouseId || undefined })),
        details: (productId: number) => getJson<ItemDetails>(url('details', { product_id: productId })),
        prices: (ids: number[]) => getJson<Record<string, string>>(url('prices', priced({ ids }))),
        totals: (body: { items: unknown[]; extra_lines?: unknown[]; document_discount: unknown; tax_rate: unknown }, signal?: AbortSignal) =>
            postJson<ServerTotals>(url('totals'), body, signal),
        partyHint: (partyId: number | string | null) => getJson<{ hint: string | null }>(url('party', { party_id: partyId || undefined })),
        exchangeRate: (currencyId: number | string | null, date: string | null) =>
            getJson<{ rate: string | null }>(url('exchange-rate', { currency_id: currencyId || undefined, date: date || undefined })),
        partiesUrl: (): string => url('parties'),
        // Service lines and customer materials (sales orders and invoices).
        servicesUrl: (): string => url('services'),
        goodsUrl: (): string => url('goods'),
        service: (productId: number) => getJson<ServicePayload | null>(url('service', priced({ product_id: productId }))),
        serviceProvidersUrl: (): string => url('service-providers'),
        createServiceProvider: (body: { name: string; email?: string | null; phone?: string | null }) =>
            sendJson<{ value: number; label: string }>(url('service-providers.store'), body),
    };
}

export type GridApi = ReturnType<typeof createGridApi>;
