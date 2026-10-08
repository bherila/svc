import type { components } from '@/types/api.generated';

/** A request body from the OpenAPI contract, by schema name. */
export type ApiSchema<Name extends keyof components['schemas']> =
    components['schemas'][Name];

export type ApiResult<T> =
    | { ok: true; status: number; data: T }
    | { ok: false; status: number; message: string };

/**
 * Call `/api/v1` as the signed-in website.
 *
 * The web UI uses the same API as OAuth clients and MCP (#385). The browser
 * authenticates with its session cookie; the server runs the same
 * request-forgery check a form post gets, so the XSRF token cookie Laravel sets
 * is echoed back. Every call is a fresh mutation attempt with its own
 * idempotency key: a double-click sends one key per click, and the version in
 * the body is what refuses the second.
 *
 * URLs come from the server as finished strings - this never assembles one.
 */
export async function apiRequest<T = unknown>(
    method: 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    url: string,
    body: object,
): Promise<ApiResult<T>> {
    let response: Response;

    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'Idempotency-Key': newIdempotencyKey(),
                ...xsrfHeader(),
            },
            body: JSON.stringify(body),
        });
    } catch {
        return {
            ok: false,
            status: 0,
            message:
                'SVC could not be reached. Check the connection and try again.',
        };
    }

    const payload: unknown = await response.json().catch(() => null);

    if (response.ok) {
        const data =
            isRecord(payload) && 'data' in payload ? payload.data : payload;

        return { ok: true, status: response.status, data: data as T };
    }

    return {
        ok: false,
        status: response.status,
        message: errorMessage(response.status, payload),
    };
}

function errorMessage(status: number, payload: unknown): string {
    if (isRecord(payload)) {
        if (isRecord(payload.errors)) {
            const first = Object.values(payload.errors)[0];

            if (Array.isArray(first) && typeof first[0] === 'string') {
                return first[0];
            }
        }

        if (typeof payload.message === 'string' && payload.message !== '') {
            return payload.message;
        }
    }

    switch (status) {
        case 401:
        case 419:
            return 'Your session has expired. Reload the page and sign in again.';
        case 403:
            return 'You do not have permission to do that.';
        case 409:
            return 'This record changed since the page loaded. Reload it and try again.';
        default:
            return 'That action could not be completed.';
    }
}

function xsrfHeader(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

function newIdempotencyKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `web-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}
