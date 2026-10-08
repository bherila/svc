import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { RestAccess } from '@/components/api-credentials';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import McpSetup from './mcp-setup';

const inertia = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));
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
        token_lifetimes: [30, 90, 365],
        issue_token_href: '/account/api-tokens',
        register_app_href: '/account/oauth-apps',
        tokens: [],
        apps: [],
        issued: null,
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
                    issued: {
                        kind: 'token',
                        name: 'n'.repeat(200),
                        token: 'eyJ'.repeat(400),
                    },
                    tokens: [
                        {
                            id: 't-1',
                            name: 'x'.repeat(200),
                            scopes: ['billing:read'],
                            created_at: null,
                            expires_at: '2026-11-07T00:00:00Z',
                            revoke_href: '/account/api-tokens/t-1',
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
                            delete_href: '/account/oauth-apps/a',
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

    it('creates an API token with the chosen permissions and lifetime', async () => {
        const user = userEvent.setup();
        render(<McpSetup serverUrl={serverUrl} available rest={rest()} />);

        const create = screen.getByRole('button', { name: 'Create API token' });
        expect(create).toBeDisabled();
        await user.type(
            screen.getByLabelText('Token name'),
            'Synthetic connector',
        );
        await user.click(screen.getAllByLabelText(/billing:read/)[0]);
        await user.click(screen.getByLabelText('90 days'));
        await user.click(create);

        expect(inertia.post).toHaveBeenCalledWith(
            '/account/api-tokens',
            { name: 'Synthetic connector', scopes: ['billing:read'], days: 90 },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows a newly issued secret once, with a way to copy it', () => {
        render(
            <McpSetup
                serverUrl={serverUrl}
                available
                rest={rest({
                    issued: {
                        kind: 'app',
                        name: 'Synthetic app',
                        client_id: 'client-1',
                        client_secret: 'synthetic-secret',
                    },
                })}
            />,
        );

        expect(screen.getByText(/will not be shown again/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Copy Client secret' }),
        ).toBeInTheDocument();
        expect(screen.getByText('synthetic-secret')).toBeInTheDocument();
    });
});
