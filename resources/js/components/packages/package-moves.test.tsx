import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PackageMoves } from '@/components/packages/package-moves';
import { moveRow } from '@/test/package-fixtures';

const api = vi.hoisted(() => ({ apiRequest: vi.fn(), refresh: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    apiRequest: api.apiRequest,
}));
vi.mock('@/lib/refresh-page-props', () => ({ refreshPageProps: api.refresh }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

const versions = [
    {
        id: 'version-2',
        number: 2,
        published_at: '2026-09-18T12:00:00+00:00',
        subscribers: 6,
        move_url:
            '/workspaces/workspace-1/packages/package-1/move?from=version-2',
    },
    {
        id: 'version-3',
        number: 3,
        published_at: '2026-11-01T12:00:00+00:00',
        subscribers: 2,
        move_url: null,
    },
];

describe('versions and moves in the package editor', () => {
    beforeEach(() => {
        api.apiRequest.mockReset();
        api.refresh.mockReset().mockResolvedValue(undefined);
    });

    it('[MIG-1] links moving an older version’s subscribers only where the server sends a link', () => {
        render(<PackageMoves panel={{ versions, moves: [] }} />);

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(1);
        expect(links[0]).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/packages/package-1/move?from=version-2',
        );
        expect(screen.getByText('6 subscribers')).toBeInTheDocument();
    });

    it('[MIG-5] cancels a scheduled move with its version once confirmed, and offers nothing for a finished one', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: {} });
        render(
            <PackageMoves
                panel={{
                    versions,
                    moves: [
                        moveRow(),
                        moveRow({
                            id: 'move-0',
                            status: 'completed',
                            cancel_url: null,
                            counts: {
                                scheduled: 0,
                                applied: 3,
                                skipped: 1,
                                cancelled: 0,
                            },
                        }),
                    ],
                }}
            />,
        );

        expect(screen.getByText('3 moved, 1 skipped')).toBeInTheDocument();
        expect(
            screen.getAllByRole('button', { name: 'Cancel move…' }),
        ).toHaveLength(1);
        await user.click(screen.getByRole('button', { name: 'Cancel move…' }));
        const dialog = await screen.findByRole('alertdialog');
        await user.click(
            within(dialog).getByRole('button', { name: 'Cancel the move' }),
        );

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/api/v1/workspaces/workspace-1/package-migrations/move-1/cancel',
                { expected_version: 'b'.repeat(64), confirm: true },
            ),
        );
        expect(api.refresh).toHaveBeenCalled();
    });

    it('[MIG-3] says why a subscriber due to move is waiting', () => {
        render(
            <PackageMoves
                panel={{
                    versions,
                    moves: [
                        moveRow({
                            subscribers: [
                                {
                                    id: 'target-1',
                                    enrollment_id: 'enrollment-1',
                                    client: {
                                        id: 'company-1',
                                        name: 'Lakeside Synthetic Bakery',
                                    },
                                    agreement: {
                                        id: 'agreement-1',
                                        title: 'Synthetic Care plan',
                                        url: '/agreements/agreement-1',
                                    },
                                    successor_agreement: null,
                                    status: 'scheduled',
                                    outcome: {
                                        code: 'period_invoiced',
                                        message:
                                            'An issued invoice already bills a period from the effective date on.',
                                        detail: null,
                                    },
                                    autopay_limit_amount: null,
                                    autopay_below_price: false,
                                    settled_at: null,
                                },
                            ],
                        }),
                    ],
                }}
            />,
        );

        expect(
            screen.getByText(
                /Lakeside Synthetic Bakery: An issued invoice already bills/,
            ),
        ).toBeInTheDocument();
    });
});
