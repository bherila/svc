import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { McpCredentialSettings } from './mcp-credential-settings';
import type { McpCredentials } from './mcp-credential-settings';

const credentials: McpCredentials = {
    connections: [
        {
            id: 'client-a',
            name: 'Synthetic assistant',
            show_all_tools: false,
            update_href: '/account/mcp-credentials/connections/client-a',
        },
        {
            id: 'client-b',
            name: 'Another assistant',
            show_all_tools: true,
            update_href: '/account/mcp-credentials/connections/client-b',
        },
    ],
    tokens: [
        {
            id: 'token-a',
            name: 'Synthetic token',
            show_all_tools: false,
            update_href: '/account/mcp-credentials/tokens/token-a',
        },
    ],
};

afterEach(() => vi.unstubAllGlobals());

function respond(status: number, body: unknown) {
    const fetch = vi
        .fn()
        .mockResolvedValue(new Response(JSON.stringify(body), { status }));
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

describe('MCP credential settings', () => {
    it('shows each credential with its own switch', () => {
        render(<McpCredentialSettings credentials={credentials} />);

        expect(
            screen.getByRole('switch', {
                name: 'Show all tools for Synthetic assistant',
            }),
        ).not.toBeChecked();
        expect(
            screen.getByRole('switch', {
                name: 'Show all tools for Another assistant',
            }),
        ).toBeChecked();
        expect(
            screen.getByRole('switch', {
                name: 'Show all tools for Synthetic token',
            }),
        ).not.toBeChecked();
        expect(screen.getAllByText('Connected app')).toHaveLength(2);
        expect(screen.getByText('MCP token')).toBeVisible();
    });

    it('sends the new value to the finished URL for that credential only', async () => {
        const user = userEvent.setup();
        const fetch = respond(200, { data: { show_all_tools: true } });
        render(<McpCredentialSettings credentials={credentials} />);

        const toggle = screen.getByRole('switch', {
            name: 'Show all tools for Synthetic token',
        });
        await user.click(toggle);

        await waitFor(() => expect(toggle).toBeChecked());
        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, init] = fetch.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/account/mcp-credentials/tokens/token-a');
        expect(init.method).toBe('PUT');
        expect(JSON.parse(init.body as string)).toEqual({
            show_all_tools: true,
        });
        expect(
            screen.getByRole('switch', {
                name: 'Show all tools for Synthetic assistant',
            }),
        ).not.toBeChecked();
    });

    it('keeps the switch where it was and says so when the change is refused', async () => {
        const user = userEvent.setup();
        respond(404, { message: 'Not found.' });
        render(<McpCredentialSettings credentials={credentials} />);

        const toggle = screen.getByRole('switch', {
            name: 'Show all tools for Synthetic assistant',
        });
        await user.click(toggle);

        expect(await screen.findByRole('alert')).toBeVisible();
        expect(toggle).not.toBeChecked();
    });

    it('says what to do when there is nothing to configure', () => {
        render(
            <McpCredentialSettings
                credentials={{ connections: [], tokens: [] }}
            />,
        );

        expect(screen.queryByRole('switch')).toBeNull();
        expect(
            screen.getByText(/No connected apps or MCP tokens yet/),
        ).toBeVisible();
    });

    it('contains oversized names without overflow risks', () => {
        const long = 'SyntheticConnectionNameWithoutBreaks'.repeat(8);
        const { container } = render(
            <McpCredentialSettings
                credentials={{
                    connections: [
                        {
                            id: 'client-long',
                            name: long,
                            show_all_tools: true,
                            update_href:
                                '/account/mcp-credentials/connections/x',
                        },
                    ],
                    tokens: [
                        {
                            id: 'token-long',
                            name: long,
                            show_all_tools: false,
                            update_href: '/account/mcp-credentials/tokens/x',
                        },
                    ],
                }}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
