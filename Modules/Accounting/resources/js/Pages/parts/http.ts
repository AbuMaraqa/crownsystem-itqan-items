/**
 * Small JSON calls of a page's own endpoints (searches, previews, pickers). Writes that change a
 * record go through Inertia (`router` / `useForm`) so the flash message and the page props follow.
 */

/** The Laravel XSRF cookie, sent back as a header on a JSON POST (Inertia's own requests do the same). */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: { message?: string; errors?: Record<string, string[]> } | null,
    ) {
        super(body?.message ?? `HTTP ${status}`);
    }

    /** The first validation message, or the server's message. */
    firstMessage(): string {
        const errors = this.body?.errors ? Object.values(this.body.errors).flat() : [];

        return errors[0] ?? this.message;
    }
}

async function parse<T>(response: Response): Promise<T> {
    if (!response.ok) {
        let body = null;

        try {
            body = await response.json();
        } catch {
            // Not JSON: the status says enough.
        }

        // bootstrap/app.php answers a JSON validation error as { message, data: { field: [..] } }.
        if (response.status === 422 && body && !body.errors && body.data && typeof body.data === 'object') {
            body = { ...body, errors: body.data };
        }

        throw new HttpError(response.status, body);
    }

    return (await response.json()) as T;
}

export async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    return parse<T>(await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal }));
}

export async function postJson<T>(url: string, body: unknown, signal?: AbortSignal): Promise<T> {
    return parse<T>(
        await fetch(url, {
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
        }),
    );
}

/** `url?a=1&b=2`, skipping empty values; arrays as `key[]`. */
export function withQuery(url: string, params: Record<string, unknown>): string {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined || value === '') continue;
        if (Array.isArray(value)) value.forEach((item) => query.append(`${key}[]`, String(item)));
        else query.append(key, String(value));
    }

    const text = query.toString();

    return text ? `${url}${url.includes('?') ? '&' : '?'}${text}` : url;
}
