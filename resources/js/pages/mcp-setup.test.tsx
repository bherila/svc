import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { RestLinks } from '@/components/rest-credentials';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import McpSetup from './mcp-setup';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

const serverUrl = 'https://svc.example.test/api/v1/mcp';

function rest(overrides: Partial<RestLinks> = {}): RestLinks {
    return {
        api_base_url: 'https://svc.example.test/api/v1',
        openapi_url: 'https://svc.example.test/openapi/svc-agent-v1.json',
        authorize_url: 'https://svc.example.test/oauth/authorize',
        token_url: 'https://svc.example.test/oauth/token',
        index_url: '/account/api-credentials',
        ...overrides,
    };
}

/** The credential section loads its index itself; an empty, issuing-off one is enough for the guide. */
beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        scopes: [],
                        token_lifetimes: ['P30D'],
                        issue_token_href: null,
                        register_app_href: null,
                        tokens: [],
                        apps: [],
                    },
                }),
                { status: 200 },
            ),
        ),
    );
});

afterEach(() => vi.unstubAllGlobals());

describe('MCP setup guide', () => {
    it('contains oversized server URLs without overflow risks', async () => {
        const { container } = render(
            <McpSetup
                serverUrl={`https://${'synthetic'.repeat(50)}.example.test/api/v1/mcp`}
                available
                rest={rest({
                    openapi_url: `https://${'synthetic'.repeat(50)}.example.test/openapi.json`,
                })}
            />,
        );
        await screen.findByText('API tokens');
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
});
