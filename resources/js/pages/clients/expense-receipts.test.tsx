import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseReceipts from '@/pages/clients/expense-receipts';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

const inertia = vi.hoisted(() => ({
    delete: vi.fn(),
    reload: vi.fn(),
    patch: vi.fn(),
    post: vi.fn(),
    visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: inertia,
    usePage: () => ({
        props: sharedPageProps({ workspaceNavigation: workspaceNavigation() }),
    }),
}));

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue(
                new Response(
                    JSON.stringify({ data: { lifecycle_state: 'deleting' } }),
                    { status: 202 },
                ),
            ),
    );
    inertia.delete.mockReset();
    inertia.patch.mockReset();
    inertia.post.mockReset();
});

afterEach(() => vi.unstubAllGlobals());

describe('expense receipts', () => {
    const file = {
        id: 'synthetic-file',
        filename: 'SyntheticReceipt'.repeat(30),
        media_type: 'text/plain',
        bytes: 12,
        uploaded_at: null,
        download_href: '/synthetic/download',
        delete_href: '/synthetic/delete',
        sharing: null,
        deletion: {
            expected_version: 'r'.repeat(64),
            expected_parent_version: 'e'.repeat(64),
        },
    };
    const props = {
        expense: {
            description: 'SyntheticDescription'.repeat(30),
            spent_on: '2026-09-01',
            amount: 12500,
            currency: 'USD',
            status: 'draft',
        },
        files: [file],
        upload: {
            href: '/synthetic/upload',
            parent_version: 'e'.repeat(64),
            description: 'Receipts are private to workspace managers.',
        },
    };
    it('wraps hostile descriptions and filenames and uses server download URLs', () => {
        const { container } = render(<ExpenseReceipts {...props} />);
        expect(
            screen.getByRole('link', { name: file.filename }),
        ).toHaveAttribute('href', file.download_href);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
    it('does not remove on cancel, and submits only after confirmation', async () => {
        const user = userEvent.setup();
        render(<ExpenseReceipts {...props} />);
        await user.click(screen.getByRole('button', { name: 'Remove file' }));
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(fetch).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Remove file' }));
        await user.click(
            screen.getByRole('button', { name: 'Confirm removal' }),
        );
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        expect(vi.mocked(fetch).mock.calls[0][0]).toBe(file.delete_href);
        expect(
            JSON.parse(String(vi.mocked(fetch).mock.calls[0][1]?.body)),
        ).toEqual({ confirm: true, ...file.deletion });
    });
});
