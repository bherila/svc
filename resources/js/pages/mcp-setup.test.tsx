import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import McpSetup from './mcp-setup';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

const serverUrl = 'https://svc.example.test/api/v1/mcp';

describe('MCP setup guide', () => {
    it('contains oversized server URLs without overflow risks', () => {
        const { container } = render(
            <McpSetup
                serverUrl={`https://${'synthetic'.repeat(50)}.example.test/api/v1/mcp`}
                available
            />,
        );
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('copies the endpoint and renders OAuth setup for each assistant', async () => {
        const user = userEvent.setup();
        const writeText = vi.spyOn(navigator.clipboard, 'writeText');
        render(<McpSetup serverUrl={serverUrl} available />);

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
        render(<McpSetup serverUrl={serverUrl} available={false} />);
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
