import { route } from '@/lib/route';

import type { WarehouseContext } from './types';

/** The Laravel XSRF cookie, sent back as a header on a JSON POST (as Inertia does). */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal });

    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    return (await response.json()) as T;
}

export async function postJson<T>(url: string, body: unknown, signal?: AbortSignal): Promise<T> {
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

/**
 * The chosen warehouse's locations, the balance of each item there and where it is kept
 * (`accounting.inventory.<document>.warehouse`).
 */
export function warehouseContext(prefix: string, warehouseId: number | string | null, productIds: number[]): Promise<WarehouseContext> {
    return getJson<WarehouseContext>(route(`accounting.inventory.${prefix}.warehouse`, { warehouse_id: warehouseId || undefined, product_ids: productIds }));
}
