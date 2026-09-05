import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { parseAmount } from '@/components/expenses/expense-dialog';
import ClientExpenses from '@/pages/clients/expenses';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { ClientExpenseRow, ExpensesPageProps } from '@/types/expenses';

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

function expense(overrides: Partial<ClientExpenseRow> = {}): ClientExpenseRow {
    return {
        id: 'expense-1',
        spent_on: '2026-03-14',
        amount: 4250,
        currency: 'USD',
        description: 'Courier for the signed contract',
        status: 'draft',
        project: { id: 'project-1', name: 'Main project' },
        approved_by: null,
        approved_at: null,
        can_edit: true,
        can_approve: true,
        can_unapprove: false,
        can_discard: true,
        urls: {
            update: '/workspaces/w/expenses/expense-1',
            approve: '/workspaces/w/expenses/expense-1/approve',
            unapprove: '/workspaces/w/expenses/expense-1/unapprove',
            discard: '/workspaces/w/expenses/expense-1',
        },
        ...overrides,
    };
}

function props(overrides: Partial<ExpensesPageProps> = {}): ExpensesPageProps {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        permissions: { record: true, approve: true },
        urls: { store: '/workspaces/w/clients/company-1/expenses' },
        projects: [{ id: 'project-1', name: 'Main project' }],
        expenses: [expense()],
        ...overrides,
    };
}

/** Every control the row itself offers, by its accessible name. */
function actionsIn(row: HTMLElement): string[] {
    return within(row)
        .queryAllByRole('button')
        .map(
            (button) =>
                button.getAttribute('aria-label') ?? button.textContent ?? '',
        );
}

describe('the expense list', () => {
    it('shows the money in the row’s own currency', () => {
        render(<ClientExpenses {...props()} />);

        expect(screen.getByText('$42.50')).toBeInTheDocument();
        expect(
            screen.getByText('Courier for the signed contract'),
        ).toBeInTheDocument();
    });

    /**
     * The lifecycle lives on the server and the row states its own moves, so
     * the page offers exactly what the server says it may. Two readings of one
     * lifecycle is how a screen comes to offer a control the server refuses.
     */
    it('offers only the moves the row says it has', () => {
        render(
            <ClientExpenses
                {...props({
                    expenses: [
                        expense({
                            status: 'approved',
                            can_edit: false,
                            can_approve: false,
                            can_unapprove: true,
                            approved_by: 'Synthetic Manager',
                        }),
                    ],
                })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Return to draft' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Approve' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit expense' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/Approved by Synthetic Manager/),
        ).toBeInTheDocument();
    });

    /**
     * An invoiced expense is on a client's bill. Changing it here would change
     * what was billed without touching the bill.
     */
    it('offers nothing on an expense that has reached a bill', () => {
        render(
            <ClientExpenses
                {...props({
                    expenses: [
                        expense({
                            status: 'invoiced',
                            can_edit: false,
                            can_approve: false,
                            can_unapprove: false,
                            can_discard: false,
                        }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('Invoiced')).toBeInTheDocument();
        // Scoped to the row: the shell's own navbar controls are buttons too,
        // and counting those would make this assertion about the chrome.
        expect(actionsIn(screen.getByRole('row', { name: /Courier/ }))).toEqual(
            [],
        );
    });

    it('posts a move to the URL the server sent', async () => {
        const user = userEvent.setup();
        render(<ClientExpenses {...props()} />);

        await user.click(screen.getByRole('button', { name: 'Approve' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/workspaces/w/expenses/expense-1/approve',
            {},
            expect.anything(),
        );
    });

    it('offers a reader nothing to press', () => {
        render(
            <ClientExpenses
                {...props({
                    permissions: { record: false, approve: false },
                    expenses: [
                        expense({
                            can_edit: false,
                            can_approve: false,
                            can_unapprove: false,
                            can_discard: false,
                        }),
                    ],
                })}
            />,
        );

        expect(actionsIn(screen.getByRole('row', { name: /Courier/ }))).toEqual(
            [],
        );
        expect(
            screen.queryByRole('button', { name: 'Record expense' }),
        ).not.toBeInTheDocument();
        // The list itself is still readable.
        expect(
            screen.getByText('Courier for the signed contract'),
        ).toBeInTheDocument();
    });

    it('says so plainly when there is nothing recorded', () => {
        render(<ClientExpenses {...props({ expenses: [] })} />);

        expect(
            screen.getByText('No expenses recorded for this client.'),
        ).toBeInTheDocument();
    });

    /**
     * jsdom measures nothing, so the check is the shape: a description that
     * runs for a paragraph must have somewhere for the overflow to go, or the
     * column sizes the table and the table sizes the page.
     */
    it('leaves nothing that can push the page sideways', () => {
        const { container } = render(
            <ClientExpenses
                {...props({
                    expenses: [
                        expense({
                            description:
                                'Reimbursable-courier-charge-with-a-reference-nobody-would-ever-type-into-this-field-by-hand',
                            project: {
                                id: 'project-1',
                                name: 'Synthetic-Project-With-No-Spaces-In-Its-Name-At-All',
                            },
                        }),
                    ],
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

/**
 * The column holds minor units, and the domain refuses anything that is not a
 * positive integer of them. So the conversion happens once, at the edge where a
 * person's "12.50" becomes 1250 — a controller accepting both shapes would have
 * to guess which one arrived.
 */
describe('reading a typed amount', () => {
    it('reads major units as minor ones', () => {
        expect(parseAmount('12.50')).toBe(1250);
        expect(parseAmount('  42 ')).toBe(4200);
        expect(parseAmount('1,250.05')).toBe(125005);
    });

    it('refuses what it cannot read rather than guessing', () => {
        expect(parseAmount('')).toBeNull();
        expect(parseAmount('0')).toBeNull();
        expect(parseAmount('0.00')).toBeNull();
        expect(parseAmount('-5')).toBeNull();
        expect(parseAmount('12.505')).toBeNull();
        expect(parseAmount('twelve')).toBeNull();
    });
});
