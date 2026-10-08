import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';

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
});
