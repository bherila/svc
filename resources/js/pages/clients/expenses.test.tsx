import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { parseAmount } from '@/components/expenses/expense-dialog';
import { apiRequest } from '@/lib/api';
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
    reload: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(),
    newIdempotencyKey: () => `synthetic-expense-${Math.random()}`,
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
    inertia.reload.mockReset().mockImplementation((options) => {
        options?.onSuccess?.({});
        options?.onFinish?.({});
    });
    vi.mocked(apiRequest)
        .mockReset()
        .mockResolvedValue({ ok: true, status: 200, data: {} });
});

function expense(overrides: Partial<ClientExpenseRow> = {}): ClientExpenseRow {
    return {
        id: 'expense-1',
        version: 'a'.repeat(64),
        spent_on: '2026-03-14',
        amount: 4250,
        currency: 'USD',
        description: 'Courier for the signed contract',
        status: 'draft',
        billing: null,
        project: { id: 'project-1', name: 'Main project' },
        approved_by: null,
        approved_at: null,
        can_edit: true,
        can_approve: true,
        can_unapprove: false,
        can_discard: true,
        urls: {
            update: '/api/v1/workspaces/w/expenses/expense-1',
            approve: '/api/v1/workspaces/w/expenses/expense-1/approve',
            unapprove: '/api/v1/workspaces/w/expenses/expense-1/unapprove',
            discard: '/api/v1/workspaces/w/expenses/expense-1',
            receipts:
                '/workspaces/w/clients/company-1/expenses/expense-1/receipts',
        },
        ...overrides,
    };
}

function props(overrides: Partial<ExpensesPageProps> = {}): ExpensesPageProps {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        permissions: { record: true, approve: true },
        urls: { store: '/api/v1/workspaces/w/expenses' },
        workspace: { timezone: 'America/Los_Angeles', default_currency: 'USD' },
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
    it('shows the server-provided invoice association and pending reason', () => {
        render(
            <ClientExpenses
                {...props({
                    expenses: [
                        expense({
                            id: 'claimed',
                            billing: {
                                invoice: {
                                    url: '/synthetic/invoice',
                                    number: 'SYN-EXP-001',
                                },
                                reason: null,
                            },
                        }),
                        expense({
                            id: 'pending',
                            billing: {
                                invoice: null,
                                reason: 'Awaiting an eligible EUR invoice covering 2026-03-14 or later.',
                            },
                        }),
                    ],
                })}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Invoice SYN-EXP-001' }),
        ).toHaveAttribute('href', '/synthetic/invoice');
        expect(
            screen.getByText(/Awaiting an eligible EUR invoice/),
        ).toBeInTheDocument();
    });

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

    /**
     * A lifecycle refusal is the ordinary case, not a bug: two managers on one
     * list, both pressing approve, and the second loses. The server answers
     * with the status the row holds now, and a screen that swallows it leaves
     * that operator watching a button do nothing.
     */
    it('shows the refusal when a move loses its race', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 409,
            message:
                'An expense with status "approved" cannot become "approved".',
        });

        render(<ClientExpenses {...props()} />);
        await user.click(screen.getByRole('button', { name: 'Approve' }));

        expect(screen.getByRole('alert')).toHaveTextContent(
            'An expense with status "approved" cannot become "approved".',
        );
    });

    it('posts a move to the URL the server sent', async () => {
        const user = userEvent.setup();
        render(<ClientExpenses {...props()} />);

        await user.click(screen.getByRole('button', { name: 'Approve' }));

        expect(apiRequest).toHaveBeenCalledWith(
            'POST',
            '/api/v1/workspaces/w/expenses/expense-1/approve',
            { expected_version: 'a'.repeat(64) },
        );
        expect(inertia.reload).toHaveBeenCalledWith({
            only: ['expenses'],
        });
    });

    it('records an expense through the batch API contract', async () => {
        const user = userEvent.setup();
        render(<ClientExpenses {...props()} />);
        await user.click(
            screen.getByRole('button', { name: 'Record expense' }),
        );
        await user.type(screen.getByLabelText('Amount'), '12.50');
        await user.type(
            screen.getByLabelText('Description'),
            'Synthetic API expense',
        );
        await user.click(screen.getByRole('button', { name: 'Record' }));
        expect(apiRequest).toHaveBeenCalledWith(
            'POST',
            '/api/v1/workspaces/w/expenses',
            {
                entries: [
                    expect.objectContaining({
                        company_id: 'company-1',
                        amount: 1250,
                        description: 'Synthetic API expense',
                    }),
                ],
            },
            expect.any(String),
        );
        expect(inertia.reload).toHaveBeenCalled();
    });

    it('keeps an edit open when its published version is stale', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 409,
            message: 'The expense changed; reload it.',
        });
        render(<ClientExpenses {...props()} />);
        await user.click(screen.getByRole('button', { name: 'Edit expense' }));
        await user.click(screen.getByRole('button', { name: 'Save' }));
        expect(apiRequest).toHaveBeenCalledWith(
            'PATCH',
            '/api/v1/workspaces/w/expenses/expense-1',
            expect.objectContaining({
                expected_version: 'a'.repeat(64),
                amount: 4250,
            }),
            expect.any(String),
        );
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(
            screen.getByText('The expense changed; reload it.'),
        ).toBeInTheDocument();
        expect(inertia.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['expenses'] }),
        );
        const firstKey = vi.mocked(apiRequest).mock.calls[0][3];
        await user.click(screen.getByRole('button', { name: 'Save' }));
        expect(vi.mocked(apiRequest).mock.calls[1][3]).not.toBe(firstKey);
    });

    it('shows a discard conflict after closing its confirmation', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 409,
            message: 'This expense was approved; reload it.',
        });
        render(<ClientExpenses {...props()} />);
        await user.click(
            screen.getByRole('button', { name: 'Discard expense' }),
        );
        await user.click(screen.getByRole('button', { name: 'Discard' }));
        expect(apiRequest).toHaveBeenCalledWith(
            'DELETE',
            '/api/v1/workspaces/w/expenses/expense-1',
            { expected_version: 'a'.repeat(64) },
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This expense was approved; reload it.',
        );
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
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

    /**
     * Stripping every comma read "12,50" - a decimal comma across most of
     * Europe - as 1250, and posted 125000 minor units: a real expense
     * overstated a hundredfold, valid on arrival and billable later.
     *
     * Guessing from the shape is no better, because "1,250" is genuinely
     * ambiguous. So a comma survives only where it is unambiguously a
     * thousands separator, and anything else is refused for the person to
     * retype.
     */
    it('refuses an ambiguous comma rather than multiplying it', () => {
        expect(parseAmount('12,50')).toBeNull();
        expect(parseAmount('1,2,3')).toBeNull();
        expect(parseAmount('1,25')).toBeNull();
        expect(parseAmount('12,5')).toBeNull();
    });

    it('still reads a comma that can only be a thousands separator', () => {
        expect(parseAmount('1,250')).toBe(125000);
        expect(parseAmount('1,250.05')).toBe(125005);
        expect(parseAmount('1,234,567')).toBe(123456700);
    });
});

it.each([0, 408, 503])(
    'locks an unknown expense after status %s and keeps its original request after a later refusal',
    async (status) => {
        const user = userEvent.setup();
        vi.mocked(apiRequest)
            .mockResolvedValueOnce({
                ok: false,
                status,
                message: 'Synthetic uncertain expense.',
            })
            .mockResolvedValueOnce({
                ok: false,
                status: 409,
                message: 'Synthetic pending expense.',
            })
            .mockResolvedValue({ ok: true, status: 201, data: {} });
        const { rerender } = render(<ClientExpenses {...props()} />);
        await user.click(
            screen.getByRole('button', { name: 'Record expense' }),
        );
        await user.type(screen.getByLabelText('Amount'), '12.50');
        await user.type(
            screen.getByLabelText('Description'),
            'Synthetic original expense',
        );
        await user.click(screen.getByRole('button', { name: 'Record' }));
        await screen.findByRole('button', { name: 'Check original request' });
        expect(screen.getByLabelText('Amount')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Close' }),
        ).not.toBeInTheDocument();
        await user.keyboard('{Escape}');
        expect(screen.getByRole('dialog')).toBeVisible();
        const original = vi.mocked(apiRequest).mock.calls[0];
        const refreshed = props();
        rerender(
            <ClientExpenses
                {...refreshed}
                company={{ ...refreshed.company, id: 'new-company-id' }}
                urls={{
                    ...refreshed.urls,
                    store: '/synthetic/new-expense-store',
                }}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await screen.findByText('Synthetic pending expense.');
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(screen.getByLabelText('Description')).toBeDisabled();
        expect(vi.mocked(apiRequest).mock.calls[1]).toEqual(original);
        await user.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(vi.mocked(apiRequest).mock.calls[2]).toEqual(original);
    },
);

it('retains an accepted expense until a failed page refresh is recovered without another create', async () => {
    const user = userEvent.setup();
    inertia.reload.mockImplementationOnce((options) => options.onFinish?.({}));
    render(<ClientExpenses {...props()} />);
    await user.click(screen.getByRole('button', { name: 'Record expense' }));
    await user.type(screen.getByLabelText('Amount'), '12.50');
    await user.type(
        screen.getByLabelText('Description'),
        'Synthetic saved expense',
    );
    await user.click(screen.getByRole('button', { name: 'Record' }));
    await screen.findByRole('button', { name: 'Refresh saved result' });
    expect(screen.getByLabelText('Description')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    await user.keyboard('{Escape}');
    expect(screen.getByRole('dialog')).toBeVisible();
    await user.click(
        screen.getByRole('button', { name: 'Refresh saved result' }),
    );
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
    expect(apiRequest).toHaveBeenCalledOnce();
    expect(inertia.reload).toHaveBeenCalledTimes(2);
});

it('gives a corrected definitively refused expense a fresh request key', async () => {
    const user = userEvent.setup();
    vi.mocked(apiRequest)
        .mockResolvedValueOnce({
            ok: false,
            status: 422,
            message: 'Synthetic validation refusal.',
        })
        .mockResolvedValue({ ok: true, status: 201, data: {} });
    render(<ClientExpenses {...props()} />);
    await user.click(screen.getByRole('button', { name: 'Record expense' }));
    await user.type(screen.getByLabelText('Amount'), '12.50');
    await user.type(
        screen.getByLabelText('Description'),
        'Synthetic first expense',
    );
    await user.click(screen.getByRole('button', { name: 'Record' }));
    await screen.findByText('Synthetic validation refusal.');
    expect(screen.getByLabelText('Description')).toBeEnabled();
    await user.clear(screen.getByLabelText('Description'));
    await user.type(
        screen.getByLabelText('Description'),
        'Synthetic corrected expense',
    );
    await user.click(screen.getByRole('button', { name: 'Record' }));
    await vi.waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
    const calls = vi.mocked(apiRequest).mock.calls;
    expect(calls[1][3]).not.toBe(calls[0][3]);
    expect(calls[1][2]).toEqual(
        expect.objectContaining({
            entries: [
                expect.objectContaining({
                    description: 'Synthetic corrected expense',
                }),
            ],
        }),
    );
});
