import type { components } from '@/types/api.generated';

/** A request body from the OpenAPI contract, by schema name. */
export type ApiSchema<Name extends keyof components['schemas']> =
    components['schemas'][Name];

export type ApiResult<T> =
    | { ok: true; status: number; data: T; location?: string }
    | {
          ok: false;
          status: number;
          message: string;
          /** A validation refusal's messages by field, when it named any. */
          errors?: Record<string, string[]>;
      };

/**
 * Call `/api/v1` as the signed-in website.
 *
 * The web UI uses the same API as OAuth clients and MCP (#385). The browser
 * authenticates with its session cookie; the server runs the same
 * request-forgery check a form post gets, so the XSRF token cookie Laravel sets
 * is echoed back. Every call is a fresh mutation attempt with its own
 * idempotency key unless the caller passes one: a double-click sends one key per
 * click, and the version in the body is what refuses the second.
 *
 * URLs come from the server as finished strings - this never assembles one.
 */
export async function apiRequest<T = unknown>(
    method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    url: string,
    body: object | FormData,
    idempotencyKey: string = newIdempotencyKey(),
): Promise<ApiResult<T>> {
    let response: Response;
    const multipart = body instanceof FormData;

    try {
        let destination = url;

        if (method === 'GET') {
            const query = new URL(url, window.location.origin);

            for (const [key, value] of Object.entries(body)) {
                if (value !== null && value !== undefined) {
                    query.searchParams.set(
                        key,
                        typeof value === 'boolean'
                            ? value
                                ? '1'
                                : '0'
                            : String(value),
                    );
                }
            }

            destination = query.toString();
        }

        response = await fetch(destination, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(multipart ? {} : { 'Content-Type': 'application/json' }),
                ...(method === 'GET'
                    ? {}
                    : { 'Idempotency-Key': idempotencyKey }),
                ...xsrfHeader(),
            },
            body:
                method === 'GET'
                    ? undefined
                    : multipart
                      ? body
                      : JSON.stringify(body),
        });
    } catch {
        return {
            ok: false,
            status: 0,
            message:
                'SVC could not be reached. Check the connection and try again.',
        };
    }

    let payload: unknown = null;

    try {
        payload = await response.json();
    } catch {
        if (response.ok && response.status !== 204 && response.status !== 205) {
            return {
                ok: false,
                status: 0,
                message:
                    'SVC’s response could not be read. Retry to check the original change.',
            };
        }
    }

    if (response.ok) {
        const data =
            isRecord(payload) && 'data' in payload ? payload.data : payload;

        const location = response.headers.get('Location');

        return {
            ok: true,
            status: response.status,
            data: data as T,
            ...(location ? { location } : {}),
        };
    }

    const errors = fieldErrors(payload);

    return {
        ok: false,
        status: response.status,
        message: errorMessage(response.status, payload),
        ...(errors === undefined ? {} : { errors }),
    };
}

function fieldErrors(payload: unknown): Record<string, string[]> | undefined {
    if (!isRecord(payload) || !isRecord(payload.errors)) {
        return undefined;
    }

    return Object.fromEntries(
        Object.entries(payload.errors).map(([field, messages]) => [
            field,
            Array.isArray(messages)
                ? messages.filter(
                      (message): message is string =>
                          typeof message === 'string',
                  )
                : [],
        ]),
    );
}

function errorMessage(status: number, payload: unknown): string {
    // Before the payload: an expired session answers 401 "Unauthenticated." or
    // 419 "CSRF token mismatch.", neither of which tells the reader what to do.
    if (status === 401 || status === 419) {
        return 'Your session has expired. Reload the page and sign in again.';
    }

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

/**
 * A fresh key for one logical mutation. A caller whose action cannot be told
 * apart by the version in its body - sending an email, which changes nothing it
 * could be checked against - keeps one key across retries of the same attempt,
 * so a request that succeeded but whose answer was lost replays instead of
 * acting twice.
 */
export function newIdempotencyKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `web-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}
