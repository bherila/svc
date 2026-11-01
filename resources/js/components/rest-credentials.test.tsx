import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { RestCredentials } from './rest-credentials';

const links = {
    api_base_url: 'https://svc.example.test/api/v1',
    openapi_url: 'https://svc.example.test/openapi/svc-agent-v1.json',
    authorize_url: 'https://svc.example.test/oauth/authorize',
    token_url: 'https://svc.example.test/oauth/token',
    index_url: '/account/api-credentials',
};

const scopes = [
    { id: 'billing:read', description: 'Read authorized invoices' },
    { id: 'billing:write', description: 'Create and update invoice drafts' },
    { id: 'time:read', description: 'Read authorized time entries' },
    { id: 'billing:deliver', description: 'Issue and send invoices' },
];

function index(overrides: Record<string, unknown> = {}) {
    return {
        scopes,
        token_lifetimes: ['P30D', 'P90D'],
        issue_token_href: '/account/api-credentials/tokens',
        register_app_href: '/account/api-credentials/apps',
        tokens: [],
        apps: [],
        ...overrides,
    };
}

/** Serves the index, and records every other request. */
function server(data: Record<string, unknown>, created?: unknown) {
    const calls: { method: string; url: string; body: unknown }[] = [];
    const mock = vi.fn((url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';

        if (method === 'GET') {
            return Promise.resolve(
                new Response(JSON.stringify({ data }), { status: 200 }),
            );
        }

        calls.push({
            method,
            url,
            body: JSON.parse(String(init?.body ?? 'null')),
        });

        return Promise.resolve(
            new Response(JSON.stringify({ data: created ?? { revoked: true } }), {
                status: 201,
            }),
        );
    });
    vi.stubGlobal('fetch', mock);

    return calls;
}

const groups = [
    {
        id: 'billing',
        label: 'Billing & payments',
        description: null,
        sensitive: false,
        scopes: ['billing:read', 'billing:write'],
    },
    {
        id: 'time',
        label: 'Time',
        description: null,
        sensitive: false,
        scopes: ['time:read'],
    },
    {
        id: 'admin',
        label: 'Admin',
        description: 'Send invoices to clients.',
        sensitive: true,
        scopes: ['billing:deliver'],
    },
];
const presets = [
    {
        id: 'read_only',
        label: 'Read-only',
        description: 'Reads, changes nothing.',
        scopes: ['billing:read', 'time:read'],
    },
];

afterEach(() => vi.unstubAllGlobals());

describe('REST credentials section', () => {
    it('renders groups, presets and Admin from the svc payload', async () => {
        server(
            index({
                scope_groups: groups,
                scope_presets: presets,
                scope_implications: {
                    'billing:write': ['billing:read'],
                    'billing:deliver': ['billing:read'],
                },
            }),
        );
        render(<RestCredentials rest={links} />);

        expect(await screen.findByText('API tokens')).toBeInTheDocument();
        expect((await screen.findAllByText('Billing & payments')).length).toBeGreaterThan(0);
        expect(screen.getAllByText('Admin').length).toBeGreaterThan(0);
        expect(screen.getAllByRole('button', { name: 'Read-only' })).not.toHaveLength(0);
        expect(screen.getAllByText('Send invoices to clients.').length).toBeGreaterThan(0);
    });

    it('posts explicit scope ids only, with the implied read ticked', async () => {
        const calls = server(
            index({
                scope_groups: groups,
                scope_presets: presets,
                scope_implications: { 'billing:write': ['billing:read'] },
            }),
            {
                kind: 'token',
                name: 'Synthetic connector',
                token: 'eyJ'.repeat(200),
                expires_at: '2027-01-08T00:00:00+00:00',
            },
        );
        const user = userEvent.setup();
        const { container } = render(<RestCredentials rest={links} />);

        await user.type(await screen.findByLabelText('Token name'), 'Synthetic connector');
        await user.click(screen.getAllByRole('button', { name: 'Read-only' })[0]);
        await user.click(screen.getByRole('button', { name: 'Create API token' }));

        expect(await screen.findByText(/will not be shown again/i)).toBeInTheDocument();
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/account/api-credentials/tokens');
        expect(calls[0].body).toMatchObject({
            name: 'Synthetic connector',
            scopes: ['billing:read', 'time:read'],
            lifetime: 'P30D',
        });
        expect(JSON.stringify(calls[0].body)).not.toMatch(/read_only|admin/i);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('offers the token form its own token_* groups and presets when present', async () => {
        server(
            index({
                scope_groups: groups,
                scope_presets: presets,
                token_scopes: scopes.slice(0, 1),
                token_scope_groups: [
                    {
                        id: 'billing',
                        label: 'Token billing group',
                        description: null,
                        sensitive: false,
                        scopes: ['billing:read'],
                    },
                ],
                token_scope_presets: [],
            }),
        );
        render(<RestCredentials rest={links} />);

        expect(await screen.findByText('Token billing group')).toBeInTheDocument();
        // The OAuth-app form still uses the scope_* payload.
        expect(screen.getAllByText('Billing & payments').length).toBeGreaterThan(0);
        const tokenForm = screen.getByLabelText('Token name').closest('form') as HTMLElement;
        expect(within(tokenForm).queryByRole('button', { name: 'Read-only' })).toBeNull();
    });

    it('falls back to the flat list without groups', async () => {
        server(index());
        render(<RestCredentials rest={links} />);

        expect((await screen.findAllByLabelText(/billing:read/)).length).toBe(2);
        expect(screen.queryByRole('button', { name: 'Read-only' })).toBeNull();
    });

    it('lists saved credentials with explicit scope ids and keeps revoking available while issuing is off', async () => {
        const calls = server(
            index({
                issue_token_href: null,
                register_app_href: null,
                tokens: [
                    {
                        id: 't-1',
                        name: 'Synthetic connector',
                        scopes: ['billing:read'],
                        created_at: null,
                        expires_at: '2026-11-07T00:00:00Z',
                        revoke_href: '/account/api-credentials/tokens/t-1',
                    },
                ],
                apps: [
                    {
                        id: 'client-1',
                        name: 'Synthetic app',
                        confidential: false,
                        redirect_uris: ['https://app.example.test/cb'],
                        scopes: ['billing:read', 'time:read'],
                        created_at: null,
                        delete_href: '/account/api-credentials/apps/client-1',
                    },
                ],
            }),
        );
        const user = userEvent.setup();
        render(<RestCredentials rest={links} />);

        expect(await screen.findByText(/billing:read, time:read/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Create API token' })).toBeNull();
        await user.click(screen.getByRole('button', { name: /Revoke/ }));
        expect(calls[0]).toMatchObject({ method: 'DELETE', url: '/account/api-credentials/tokens/t-1' });
    });
});
