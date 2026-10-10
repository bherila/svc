import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { lifetimeLabel } from '@/components/api-credentials';
import type { RestAccess } from '@/components/api-credentials';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import McpSetup from './mcp-setup';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: inertia }));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

const serverUrl = 'https://svc.example.test/api/v1/mcp';

function rest(overrides: Partial<RestAccess> = {}): RestAccess {
    return {
        api_base_url: 'https://svc.example.test/api/v1',
        openapi_url: 'https://svc.example.test/openapi/svc-agent-v1.json',
        authorize_url: 'https://svc.example.test/oauth/authorize',
        token_url: 'https://svc.example.test/oauth/token',
        scopes: [
            { id: 'billing:read', description: 'Read authorized invoices' },
            { id: 'time:read', description: 'Read authorized time entries' },
        ],
        token_lifetimes: ['P30D', 'P90D', 'P365D'],
        issue_token_href: '/account/api-credentials/tokens',
        register_app_href: '/account/api-credentials/apps',
        tokens: [],
        apps: [],
        ...overrides,
    };
}

describe('MCP setup guide', () => {
    it('contains oversized server URLs without overflow risks', () => {
        const { container } = render(
            <McpSetup
                serverUrl={`https://${'synthetic'.repeat(50)}.example.test/api/v1/mcp`}
                available
                rest={rest({
                    openapi_url: `https://${'synthetic'.repeat(50)}.example.test/openapi.json`,
                    tokens: [
                        {
                            id: 't-1',
                            name: 'x'.repeat(200),
                            scopes: ['billing:read'],
                            created_at: null,
                            expires_at: '2026-11-07T00:00:00Z',
                            revoke_href: '/account/api-credentials/tokens/t-1',
                        },
                    ],
                    apps: [
                        {
                            id: 'a'.repeat(36),
                            name: 'y'.repeat(200),
                            confidential: true,
                            redirect_uris: [
                                `https://${'z'.repeat(300)}.example.test/cb`,
                            ],
                            scopes: ['billing:read'],
                            created_at: null,
                            delete_href: '/account/api-credentials/apps/a',
                        },
                    ],
                })}
            />,
        );
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('copies the endpoint and renders OAuth setup for each assistant', async () => {
        const user = userEvent.setup();
        const writeText = vi.spyOn(navigator.clipboard, 'writeText');
        render(<McpSetup serverUrl={serverUrl} available rest={rest()} />);

        for (const name of ['ChatGPT', 'Claude', 'Codex']) {
            expect(screen.getByRole('heading', { name })).toBeInTheDocument();
        }

        await user.click(
            screen.getByRole('button', { name: 'Copy Server URL' }),
        );
        expect(writeText).toHaveBeenCalledWith(serverUrl);
        expect(screen.getByText('Copied')).toBeInTheDocument();
        expect(screen.getByText(/codex mcp add svc/)).toHaveTextContent(
            serverUrl,
        );
    });

    it('provides manual copying when clipboard access fails', async () => {
        const user = userEvent.setup();
        vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(
            new Error('denied'),
        );
        render(
            <McpSetup serverUrl={serverUrl} available={false} rest={rest()} />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Copy Server URL' }),
        );
        expect(
            screen.getByText('Select the text below and copy it manually.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'currently unavailable',
        );
    });

    afterEach(() => vi.unstubAllGlobals());

    /**
     * The secret arrives in the creation response and is shown from memory:
     * only the lists are reloaded, so it never becomes a page prop.
     */
    it('creates an API token, shows its secret once and reloads only the lists', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        kind: 'token',
                        name: 'Synthetic connector',
                        token: 'eyJ'.repeat(400),
                        expires_at: '2027-01-08T00:00:00+00:00',
                    },
                }),
                { status: 201 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        const { container } = render(
            <McpSetup serverUrl={serverUrl} available rest={rest()} />,
        );

        const create = screen.getByRole('button', { name: 'Create API token' });
        expect(create).toBeDisabled();
        await user.type(
            screen.getByLabelText('Token name'),
            'Synthetic connector',
        );
        await user.click(screen.getAllByLabelText(/billing:read/)[0]);
        await user.click(screen.getByLabelText('90 days'));
        await user.click(create);

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/account/api-credentials/tokens');
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual({
            name: 'Synthetic connector',
            scopes: ['billing:read'],
            lifetime: 'P90D',
        });
        expect(
            await screen.findByText(/will not be shown again/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Copy API token' }),
        ).toBeInTheDocument();
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['rest'] });
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('shows a refusal and keeps the form', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        message: 'Invalid.',
                        errors: {
                            redirect_uris: ['Redirect URIs must be https.'],
                        },
                    }),
                    { status: 422 },
                ),
            ),
        );
        const user = userEvent.setup();
        render(<McpSetup serverUrl={serverUrl} available rest={rest()} />);

        await user.type(screen.getByLabelText('App name'), 'Synthetic app');
        await user.type(
            screen.getByLabelText('Redirect URIs (one per line)'),
            'http://app.example.test/cb',
        );
        await user.click(screen.getAllByLabelText(/billing:read/)[1]);
        await user.click(
            screen.getByRole('button', { name: 'Register OAuth app' }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Redirect URIs must be https.',
        );
        expect(screen.queryByText(/will not be shown again/)).toBeNull();
        expect(screen.getByLabelText('App name')).toHaveValue('Synthetic app');
    });

    it('lists each app with the permissions it may request', () => {
        render(
            <McpSetup
                serverUrl={serverUrl}
                available
                rest={rest({
                    apps: [
                        {
                            id: 'client-1',
                            name: 'Synthetic app',
                            confidential: false,
                            redirect_uris: ['https://app.example.test/cb'],
                            scopes: ['billing:read', 'time:read'],
                            created_at: null,
                            delete_href:
                                '/account/api-credentials/apps/client-1',
                        },
                    ],
                })}
            />,
        );

        expect(
            screen.getByText('May request: billing:read, time:read'),
        ).toBeInTheDocument();
    });

    /** Revoking answers JSON; only the lists reload, never a full Inertia visit. */
    it('revokes a token through the API and reloads only the lists', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: { revoked: true } }), {
                status: 200,
            }),
        );
        vi.stubGlobal('fetch', fetchMock);
        inertia.reload.mockClear();
        const user = userEvent.setup();
        render(
            <McpSetup
                serverUrl={serverUrl}
                available
                rest={rest({
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
                })}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Revoke' }));

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/account/api-credentials/tokens/t-1');
        expect(init.method).toBe('DELETE');
        expect(inertia.delete).not.toHaveBeenCalled();
        await vi.waitFor(() =>
            expect(inertia.reload).toHaveBeenCalledWith({ only: ['rest'] }),
        );
    });

    it('hides the creation forms while issuing is switched off', () => {
        render(
            <McpSetup
                serverUrl={serverUrl}
                available={false}
                rest={rest({ issue_token_href: null, register_app_href: null })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Create API token' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Register OAuth app' }),
        ).toBeNull();
        expect(
            screen.getAllByText(/New credentials cannot be created right now/),
        ).toHaveLength(2);
    });

    it('labels ISO-8601 token lifetimes', () => {
        expect(lifetimeLabel('P30D')).toBe('30 days');
        expect(lifetimeLabel('P1D')).toBe('1 day');
        expect(lifetimeLabel('PT4H')).toBe('4 hours');
        expect(lifetimeLabel('P1DT12H')).toBe('1 day 12 hours');
        expect(lifetimeLabel('P2W')).toBe('2 weeks');
        expect(lifetimeLabel('nonsense')).toBe('nonsense');
    });
});
