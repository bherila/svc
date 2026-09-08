import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseReceipts from '@/pages/clients/expense-receipts';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

const inertia = vi.hoisted(() => ({
    delete: vi.fn(),
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
    inertia.delete.mockReset();
    inertia.patch.mockReset();
    inertia.post.mockReset();
});

describe('expense receipts', () => {
    const file = {
        id: 'synthetic-file',
        filename: 'SyntheticReceipt'.repeat(30),
        media_type: 'text/plain',
        bytes: 12,
        uploaded_at: null,
        download_href: '/synthetic/download',
        delete_href: '/synthetic/delete',
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
        upload_href: '/synthetic/upload',
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
        await user.click(
            screen.getByRole('button', { name: 'Remove' }),
        );
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(inertia.delete).not.toHaveBeenCalled();
        await user.click(
            screen.getByRole('button', { name: 'Remove' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Remove receipt' }),
        );
        expect(inertia.delete).toHaveBeenCalledWith(
            file.delete_href,
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
