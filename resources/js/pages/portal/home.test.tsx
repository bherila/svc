import { router } from '@inertiajs/react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PortalHome from '@/pages/portal/home';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { PortalHomeProps, PortalToApprove } from '@/types/portal';

const style = vi.hoisted(() => ({ value: 'decimal' as 'decimal' | 'clock' }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: vi.fn(), post: vi.fn() },
    usePage: () => {
        const shared = sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        });

        return {
            props: {
                ...shared,
                auth: {
                    user: { ...shared.auth.user!, duration_style: style.value },
                },
            },
        };
    },
}));

const LONG = 'Unbroken'.repeat(12);

function props(overrides: Partial<PortalHomeProps> = {}): PortalHomeProps {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        balance: {
            amount_due: 920000,
            currency: 'USD',
            invoice_count: 1,
            overdue: false,
            overdue_amount: 0,
            first: {
                id: 'invoice-1',
                number: 'SYN-1041',
                balance: 920000,
                currency: 'USD',
                due_date: '2026-11-15',
                days_until_due: 9,
            },
        },
        last_payment: {
            amount: 880000,
            currency: 'USD',
            received_on: '2026-10-12',
        },
        pay: {
            href: 'https://pay.example.test/invoice-1',
            invoice_href: '/portal/company-1/invoices/invoice-1',
        },
        this_period: {
            agreement: {
                id: 'agreement-1',
                title: 'Synthetic retainer',
                href: '/portal/company-1/agreements/agreement-1',
            },
            period_start: '2026-11-01',
            period_end: '2026-11-30',
            use: {
                minutes_used: 1890,
                minutes_included: 2400,
                rollover_minutes: 360,
                overage_minutes: 0,
            },
            next_invoice: {
                period_start: '2026-12-01',
                period_end: '2026-12-31',
                currency: 'USD',
                total: 815000,
                parts: [
                    {
                        kind: 'retainer',
                        description: 'Retainer',
                        amount: 800000,
                        date: '2026-12-01',
                        hours: null,
                    },
                    {
                        kind: 'recurring_item',
                        description: 'Web hosting',
                        amount: 15000,
                        date: '2026-12-01',
                        hours: null,
                    },
                    {
                        kind: 'overage',
                        description: 'Hours past the retainer so far',
                        amount: null,
                        date: null,
                        hours: 1.5,
                    },
                ],
                excludes: 'tax and expenses',
            },
            still_deferred: null,
        },
        recent_invoices: [
            {
                id: 'invoice-1',
                invoice_number: 'SYN-1041',
                status: 'partially_paid',
                currency: 'USD',
                issue_date: '2026-10-01',
                due_date: '2026-11-15',
                total_amount: 920000,
                paid_amount: 0,
                balance_amount: 920000,
                href: '/portal/company-1/invoices/invoice-1',
            },
        ],
        recent_time: [
            {
                id: 'time-1',
                worked_on: '2026-11-02',
                project: 'Synthetic Project',
                description: 'Synthetic client summary',
                minutes: 150,
            },
        ],
        open_tasks: [
            {
                id: 'task-1',
                title: 'Confirm the synthetic list',
                project: 'Synthetic Project',
                status: 'in_progress',
            },
        ],
        engagement: null,
        links: {
            invoices: '/portal/company-1/invoices',
            time: '/portal/company-1/time',
            tasks: '/portal/company-1/tasks',
        },
        to_approve: null,
        approval_result: null,
        approvals_href: null,
        ...overrides,
    };
}

describe('portal home', () => {
    it('puts what is owed first, with the invoice, the pay link and the last payment', () => {
        render(<PortalHome {...props()} />);

        // The headline, then the invoice history row's total and balance.
        expect(screen.getAllByText('$9,200.00')).toHaveLength(3);
        expect(
            screen.getByText(/Invoice SYN-1041 · due .* \(in 9 days\)/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Pay $9,200.00, invoice SYN-1041',
            }),
        ).toHaveAttribute('href', 'https://pay.example.test/invoice-1');
        expect(
            screen.getByRole('link', { name: /View invoice/ }),
        ).toHaveAttribute('href', '/portal/company-1/invoices/invoice-1');
        expect(
            screen.getByText(/Last payment \$8,800\.00/),
        ).toBeInTheDocument();
    });

    it('flags an overdue balance in words, not colour alone', () => {
        render(
            <PortalHome
                {...props({
                    balance: {
                        ...props().balance,
                        overdue: true,
                        overdue_amount: 920000,
                        first: {
                            ...props().balance.first!,
                            days_until_due: -3,
                        },
                    },
                })}
            />,
        );

        expect(screen.getByText(/Overdue: \$9,200\.00/)).toBeInTheDocument();
        expect(screen.getByText(/\(3 days overdue\)/)).toBeInTheDocument();
    });

    it('offers no Pay button when the invoice has no payment page', () => {
        render(
            <PortalHome
                {...props({
                    pay: {
                        href: null,
                        invoice_href: '/portal/company-1/invoices/invoice-1',
                    },
                })}
            />,
        );

        expect(
            screen.queryByRole('link', { name: /^Pay / }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /View invoice/ }),
        ).toBeInTheDocument();
    });

    it('says nothing is due and offers nothing to pay', () => {
        render(
            <PortalHome
                {...props({
                    balance: {
                        amount_due: 0,
                        currency: null,
                        invoice_count: 0,
                        overdue: false,
                        overdue_amount: 0,
                        first: null,
                    },
                    pay: { href: null, invoice_href: null },
                    last_payment: null,
                })}
            />,
        );

        expect(screen.getByText('Nothing due')).toBeInTheDocument();
        expect(
            screen.getByText('No payments received yet.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Pay/ })).toBeNull();
    });

    it('withholds a sum across currencies', () => {
        render(
            <PortalHome
                {...props({
                    balance: {
                        ...props().balance,
                        amount_due: null,
                        currency: null,
                        invoice_count: 2,
                    },
                })}
            />,
        );

        expect(screen.getByText('Several currencies')).toBeInTheDocument();
    });

    it('shows hours used against hours available including rollover, and the estimate with its parts', () => {
        render(<PortalHome {...props()} />);

        // 31.5 h used of 2400 + 360 minutes = 46 h available.
        expect(screen.getByText('31.5 h / 46 h')).toBeInTheDocument();
        expect(
            screen.getByText('Includes 6 h rolled over.'),
        ).toBeInTheDocument();

        const estimate = screen.getByRole('region', {
            name: 'Next invoice (estimate)',
        });

        expect(within(estimate).getByText('$8,150.00')).toBeInTheDocument();
        expect(within(estimate).getByText('Web hosting')).toBeInTheDocument();
        expect(
            within(estimate).getByText(
                /Hours past the retainer so far \(1\.5 h\)/,
            ),
        ).toBeInTheDocument();
        expect(
            within(estimate).getByText('Priced when billed'),
        ).toBeInTheDocument();
        expect(
            within(estimate).getByText(
                /Before tax and expenses\. An estimate, not an invoice\./,
            ),
        ).toBeInTheDocument();
    });

    it('draws no forecast beyond the next invoice', () => {
        render(<PortalHome {...props()} />);

        expect(screen.queryByText(/12.month|forecast|projected/i)).toBeNull();
    });

    it('teaches what the empty This period card means', () => {
        render(<PortalHome {...props({ this_period: null })} />);

        expect(
            screen.getByText(/Once an agreement is in force/),
        ).toBeInTheDocument();
    });

    it('says when nothing is scheduled for the next invoice', () => {
        const base = props();
        render(
            <PortalHome
                {...props({
                    this_period: { ...base.this_period!, next_invoice: null },
                })}
            />,
        );

        expect(
            screen.getByText('No recurring charges are scheduled yet.'),
        ).toBeInTheDocument();
    });

    it('shows deferred work only when the server sent it', () => {
        const base = props();
        const { rerender } = render(<PortalHome {...base} />);

        expect(screen.queryByText(/still deferred/)).toBeNull();

        rerender(
            <PortalHome
                {...props({
                    this_period: {
                        ...base.this_period!,
                        still_deferred: {
                            entry_count: 2,
                            total_hours: 3,
                            oldest_worked_on: '2026-04-01',
                        },
                    },
                })}
            />,
        );

        expect(
            screen.getByText(/3 h of work is still deferred, the oldest from/),
        ).toBeInTheDocument();
    });

    it('lists tasks, approved time and invoice history with statuses as words', () => {
        render(<PortalHome {...props()} />);

        expect(screen.getByText('In progress')).toBeInTheDocument();
        expect(
            screen.getByText('Synthetic client summary'),
        ).toBeInTheDocument();
        expect(screen.getByText('Partially paid')).toBeInTheDocument();
        expect(screen.queryByText('partially_paid')).toBeNull();
        expect(screen.getByRole('link', { name: 'SYN-1041' })).toHaveAttribute(
            'href',
            '/portal/company-1/invoices/invoice-1',
        );
        expect(
            screen.getByRole('link', { name: 'All invoices' }),
        ).toBeInTheDocument();
    });

    it('draws no approval section for someone who may not approve', () => {
        render(<PortalHome {...props()} />);

        expect(screen.queryByText(/to approve/i)).toBeNull();
    });

    describe('to approve', () => {
        const toApprove: PortalToApprove = {
            approve_href: '/portal/company-1/approvals/approve',
            dispute_href: '/portal/company-1/approvals/dispute',
            truncated: false,
            groups: [
                {
                    key: '2026-10',
                    vendor: 'Synthetic Vendor',
                    period: '2026-10',
                    period_label: 'October 2026',
                    hours: {
                        minutes: 150,
                        pending_count: 2,
                        entries: [
                            {
                                id: 'time-1',
                                worked_on: '2026-10-14',
                                minutes: 90,
                                description: 'Synthetic client text',
                                project: 'Synthetic Project',
                                state: 'pending',
                                dispute_note: null,
                                invoice: {
                                    number: 'SYN-1042',
                                    href: '/portal/company-1/invoices/invoice-2',
                                },
                            },
                            {
                                id: 'time-2',
                                worked_on: '2026-10-15',
                                minutes: 60,
                                description: 'Second entry',
                                project: null,
                                state: 'pending',
                                dispute_note: null,
                                invoice: null,
                            },
                            {
                                id: 'time-3',
                                worked_on: '2026-10-16',
                                minutes: 30,
                                description: 'Already questioned',
                                project: null,
                                state: 'disputed',
                                dispute_note: 'Too long',
                                invoice: null,
                            },
                        ],
                    },
                    expenses: {
                        pending_count: 1,
                        amount: 4200,
                        currency: 'USD',
                        items: [
                            {
                                id: 'expense-1',
                                spent_on: '2026-10-02',
                                description: 'Synthetic travel',
                                amount: 4200,
                                currency: 'USD',
                                project: null,
                                state: 'pending',
                                dispute_note: null,
                                invoice: null,
                            },
                        ],
                    },
                },
            ],
        };

        beforeEach(() => {
            vi.mocked(router.post)
                .mockReset()
                .mockImplementation((_url, _body, options) => {
                    options?.onSuccess?.({} as never);
                    options?.onFinish?.({} as never);
                });
        });

        it('groups by vendor and month, with hours as time and expenses as money', () => {
            render(<PortalHome {...props({ to_approve: toApprove })} />);

            const section = screen.getByRole('region', {
                name: 'Synthetic Vendor · October 2026',
            });

            expect(
                within(section).getByText('2.5 h of time · $42.00 of expenses'),
            ).toBeInTheDocument();
            expect(
                within(section).getByRole('link', { name: 'Invoice SYN-1042' }),
            ).toHaveAttribute('href', '/portal/company-1/invoices/invoice-2');
            // Hours are never priced here.
            expect(
                screen.getByText('Second entry').closest('li')!.textContent,
            ).not.toContain('$');
        });

        it('approves a month with a check icon, posting to the server-sent url', async () => {
            render(<PortalHome {...props({ to_approve: toApprove })} />);

            const button = screen.getByRole('button', {
                name: 'Approve October 2026 from Synthetic Vendor',
            });

            expect(button.querySelector('svg.lucide-check')).not.toBeNull();
            await userEvent.click(button);

            expect(router.post).toHaveBeenCalledWith(
                '/portal/company-1/approvals/approve',
                { period: '2026-10' },
                expect.objectContaining({ preserveScroll: true }),
            );
        });

        it('approves one entry or one expense by name', async () => {
            render(<PortalHome {...props({ to_approve: toApprove })} />);

            await userEvent.click(
                screen.getByRole('button', {
                    name: 'Approve Synthetic client text',
                }),
            );
            await userEvent.click(
                screen.getByRole('button', {
                    name: 'Approve Synthetic travel',
                }),
            );

            expect(
                vi.mocked(router.post).mock.calls.map((call) => call[1]),
            ).toEqual([
                { time_ids: ['time-1'] },
                { expense_ids: ['expense-1'] },
            ]);
        });

        it('disputes with a note, or without one', async () => {
            render(<PortalHome {...props({ to_approve: toApprove })} />);

            await userEvent.click(
                screen.getByRole('button', {
                    name: 'Dispute Synthetic client text',
                }),
            );
            await userEvent.type(
                screen.getByLabelText('Note for your provider (optional)'),
                'Too many hours',
            );
            await userEvent.click(
                screen.getByRole('button', { name: 'Send dispute' }),
            );
            await userEvent.click(
                screen.getByRole('button', { name: 'Dispute Second entry' }),
            );
            await userEvent.click(
                screen.getByRole('button', { name: 'Send dispute' }),
            );

            expect(
                vi
                    .mocked(router.post)
                    .mock.calls.map((call) => [call[0], call[1]]),
            ).toEqual([
                [
                    '/portal/company-1/approvals/dispute',
                    { kind: 'time', id: 'time-1', note: 'Too many hours' },
                ],
                [
                    '/portal/company-1/approvals/dispute',
                    { kind: 'time', id: 'time-2', note: '' },
                ],
            ]);
        });

        it('shows a disputed row with its note and no controls', () => {
            render(<PortalHome {...props({ to_approve: toApprove })} />);

            expect(screen.getByText('Your note: Too long')).toBeInTheDocument();
            expect(
                screen.queryByRole('button', {
                    name: 'Approve Already questioned',
                }),
            ).toBeNull();
        });

        it('says what the last decision did, including what it left alone', () => {
            render(
                <PortalHome
                    {...props({
                        to_approve: toApprove,
                        approval_result: {
                            kind: 'approved',
                            time: 2,
                            expenses: 1,
                            skipped_own: 1,
                        },
                    })}
                />,
            );

            expect(
                screen.getByRole('status', {
                    name: '',
                    description: '',
                }).textContent,
            ).toBe(
                'Approved 2 entries of time and 1 expense. 1 of your own was left for someone else to approve.',
            );
        });

        it('has a calm empty state', () => {
            render(
                <PortalHome
                    {...props({ to_approve: { ...toApprove, groups: [] } })}
                />,
            );

            expect(screen.getByText(/Nothing is waiting/)).toBeInTheDocument();
        });

        it('holds with unbroken names and no overflow risk', () => {
            const hostile = structuredClone(toApprove);
            hostile.groups[0].vendor = LONG;
            hostile.groups[0].hours.entries[0].description = LONG;
            hostile.groups[0].hours.entries[0].project = LONG;
            hostile.groups[0].hours.entries[0].invoice!.number = LONG;
            hostile.groups[0].hours.entries[2].dispute_note = LONG;
            hostile.groups[0].expenses.items[0].description = LONG;

            const { container } = render(
                <PortalHome {...props({ to_approve: hostile })} />,
            );

            expect(horizontalOverflowRisks(container)).toEqual([]);
        });
    });

    it('holds with unbroken names and no overflow risk', () => {
        const hostile = props();
        hostile.company.name = LONG;
        hostile.balance.first!.number = LONG;
        hostile.this_period!.agreement.title = LONG;
        hostile.this_period!.next_invoice!.parts[1].description = LONG;
        hostile.recent_invoices[0].invoice_number = LONG;
        hostile.recent_time[0].description = LONG;
        hostile.recent_time[0].project = LONG;
        hostile.open_tasks[0].title = LONG;
        hostile.open_tasks[0].project = LONG;

        const { container } = render(<PortalHome {...hostile} />);

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
