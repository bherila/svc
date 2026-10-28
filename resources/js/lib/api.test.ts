import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';

function respond(status: number, body: unknown) {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue(new Response(JSON.stringify(body), { status })),
    );
}

describe('apiRequest refusals', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('encodes read filters without a mutation header or request body', async () => {
        respond(200, { data: { minutes: 90 } });
        expect(
            await apiRequest('GET', '/api/v1/finished?existing=kept', {
                hours: 1.5,
                round_up: false,
                discount_reason: null,
            }),
        ).toMatchObject({ ok: true, data: { minutes: 90 } });
        const [destination, init] = vi.mocked(fetch).mock.calls[0];
        const url = new URL(String(destination));
        expect(url.pathname).toBe('/api/v1/finished');
        expect(Object.fromEntries(url.searchParams)).toEqual({
            existing: 'kept',
            hours: '1.5',
            round_up: '0',
        });
        expect(init?.body).toBeUndefined();
        expect(init?.headers).not.toHaveProperty('Idempotency-Key');
    });

    it('accepts the hidden-by-default task request without an explicit visibility field', async () => {
        const body: ApiSchema<'TaskCreateRequest'> = {
            title: 'Synthetic hidden task',
        };
        respond(201, { data: { is_visible_to_client: false } });
        const result = await apiRequest('POST', '/api/v1/example', body);
        expect(result.ok).toBe(true);
        const init = vi.mocked(fetch).mock.calls[0][1];
        expect(JSON.parse(String(init?.body))).toEqual({
            title: 'Synthetic hidden task',
        });
    });

    it('sends multipart bytes with the same XSRF and retry headers without overriding the boundary', async () => {
        document.cookie = 'XSRF-TOKEN=synthetic%3Dtoken';
        const body = new FormData();
        body.set('file', new File(['Synthetic bytes'], 'synthetic.txt'));
        body.set('expected_version', 'v'.repeat(64));
        respond(200, { data: { id: 'synthetic-file' } });
        await apiRequest(
            'POST',
            '/synthetic/native-upload',
            body,
            'synthetic-retry',
        );
        const [destination, init] = vi.mocked(fetch).mock.calls[0];
        expect(destination).toBe('/synthetic/native-upload');
        expect(init?.body).toBe(body);
        expect(init?.headers).not.toHaveProperty('Content-Type');
        expect(init?.headers).toMatchObject({
            'Idempotency-Key': 'synthetic-retry',
            'X-XSRF-TOKEN': 'synthetic=token',
        });
        document.cookie = 'XSRF-TOKEN=; Max-Age=0';
    });

    it.each([
        [401, { message: 'Unauthenticated.' }],
        [419, { message: 'CSRF token mismatch.' }],
    ])(
        'tells the reader to sign in again on %i rather than echoing the framework message',
        async (status, body) => {
            respond(status, body);

            const result = await apiRequest('PATCH', '/api/v1/example', {});

            expect(result).toEqual({
                ok: false,
                status,
                message:
                    'Your session has expired. Reload the page and sign in again.',
            });
        },
    );

    it('prefers the first validation error, then the message', async () => {
        respond(422, {
            message: 'The given data was invalid.',
            errors: { due_date: ['The due date is not a date.'] },
        });
        expect(await apiRequest('PATCH', '/api/v1/example', {})).toMatchObject({
            message: 'The due date is not a date.',
            errors: { due_date: ['The due date is not a date.'] },
        });

        respond(409, {
            message: 'The invoice has changed; read it and retry.',
        });
        expect(await apiRequest('PATCH', '/api/v1/example', {})).toMatchObject({
            message: 'The invoice has changed; read it and retry.',
        });
    });

    it('explains an unreachable server', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockRejectedValue(new TypeError('Failed to fetch')),
        );

        expect(await apiRequest('PATCH', '/api/v1/example', {})).toMatchObject({
            ok: false,
            status: 0,
        });
    });
    it('treats an interrupted successful JSON response as uncertain', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(new Response('{"data":', { status: 201 })),
        );
        expect(
            await apiRequest(
                'POST',
                '/api/v1/example',
                {},
                'synthetic-attempt',
            ),
        ).toMatchObject({
            ok: false,
            status: 0,
            message: expect.stringContaining('could not be read'),
        });
    });

    it('accepts intentionally empty successful responses', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response(null, { status: 204 })),
        );
        expect(
            await apiRequest(
                'DELETE',
                '/api/v1/example',
                {},
                'synthetic-delete',
            ),
        ).toEqual({ ok: true, status: 204, data: null });
    });
});
