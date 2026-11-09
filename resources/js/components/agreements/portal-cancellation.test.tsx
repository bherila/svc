import { router } from '@inertiajs/react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    cancellationTermsSentence,
    PortalCancellationCard,
} from '@/components/agreements/portal-cancellation';
import type { PortalCancellation } from '@/types/agreement';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
}));

const CANCEL = '/portal/company-1/agreements/agreement-1/cancellation';

function panel(
    overrides: Partial<PortalCancellation> = {},
): PortalCancellation {
    return {
        mode: 'request',
        notice_days: 30,
        minimum_term_months: 6,
        minimum_term_ends_on: '2027-05-31',
        earliest_end_on: '2027-05-31',
        offered_end_dates: ['2027-05-31', '2027-06-30', '2027-07-31'],
        pending_request: null,
        scheduled_end: null,
        provider_name: 'Synthetic Studio',
        balance: {
            amount_due: 0,
            currency: 'USD',
            invoice_count: 0,
            overdue: false,
            overdue_amount: null,
        },
        cancel_url: CANCEL,
        cancel_refusal: null,
        withdraw_url: null,
        undo_url: null,
        cancelled_by_client: false,
        ...overrides,
    };
}

beforeEach(() => {
    vi.mocked(router.post).mockReset();
    vi.mocked(router.post).mockImplementation((_url, _data, options) => {
        options?.onFinish?.({} as never);
    });
});

describe('PortalCancellationCard', () => {
    it('[CAN-2] [CAN-3] offers exactly the dates the server sends and requests the one chosen', async () => {
        const user = userEvent.setup();
        render(
            <PortalCancellationCard
                panel={panel()}
                serviceName="Hosting + Care plan"
            />,
        );

        expect(
            screen.getByText(
                '6-month minimum (to May 31, 2027), then 30 days’ notice',
            ),
        ).toBeInTheDocument();
        const select = screen.getByRole('combobox', { name: 'End on' });
        // The trigger shows the label, never the raw date (Select items).
        expect(select).toHaveTextContent('May 31, 2027 (earliest)');
        await user.click(select);
        expect(
            (await screen.findAllByRole('option')).map(
                (option) => option.textContent,
            ),
        ).toEqual(['May 31, 2027 (earliest)', 'Jun 30, 2027', 'Jul 31, 2027']);
        await user.click(screen.getByRole('option', { name: 'Jun 30, 2027' }));
        await user.type(
            screen.getByLabelText('Reason (optional)'),
            'Moving the site in-house.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Request cancellation…' }),
        );
        const dialog = await screen.findByRole('alertdialog');
        expect(
            within(dialog).getByText(
                /Synthetic Studio is asked to end Hosting \+ Care plan on Jun 30, 2027/,
            ),
        ).toBeInTheDocument();
        await user.click(
            within(dialog).getByRole('button', { name: 'Send request' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            CANCEL,
            { end_date: '2027-06-30', reason: 'Moving the site in-house.' },
            expect.anything(),
        );
    });

    it('[CAN-3] [EDGE-9] schedules a self-serve end at once and says it can be undone', async () => {
        const user = userEvent.setup();
        render(
            <PortalCancellationCard
                panel={panel({
                    mode: 'self_serve',
                    minimum_term_months: null,
                    minimum_term_ends_on: null,
                    earliest_end_on: '2026-12-31',
                    offered_end_dates: ['2026-12-31', '2027-01-31'],
                })}
                serviceName="Web hosting"
            />,
        );

        expect(
            screen.getByText(
                '30 days’ notice, ending at the close of a billing period',
            ),
        ).toBeInTheDocument();
        await user.click(
            screen.getByRole('button', { name: 'Cancel on Dec 31, 2026…' }),
        );
        const dialog = await screen.findByRole('alertdialog');
        expect(
            within(dialog).getByText(/You can undo this until that date/),
        ).toBeInTheDocument();
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Cancel on Dec 31, 2026',
            }),
        );

        expect(router.post).toHaveBeenCalledWith(
            CANCEL,
            { end_date: '2026-12-31' },
            expect.anything(),
        );
    });

    it('[EDGE-11] says what is still owed on the confirmation, and that it stays owed', async () => {
        const user = userEvent.setup();
        render(
            <PortalCancellationCard
                panel={panel({
                    balance: {
                        amount_due: 12000,
                        currency: 'USD',
                        invoice_count: 1,
                        overdue: true,
                        overdue_amount: 12000,
                    },
                })}
                serviceName="Web hosting"
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Request cancellation…' }),
        );
        expect(
            await screen.findByText(
                /\$120\.00 is overdue on an invoice already issued\. Cancelling does not change it: it stays owed\./,
            ),
        ).toBeInTheDocument();
        // Never a reason to refuse: the request can still be sent.
        expect(
            screen.getByRole('button', { name: 'Send request' }),
        ).toBeEnabled();
    });

    it('[CAN-3] renders no cancel control when the server sends no URL', () => {
        const { rerender } = render(
            <PortalCancellationCard
                panel={panel({ cancel_url: null })}
                serviceName="Web hosting"
            />,
        );
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Someone with access to the whole company can cancel this.',
            ),
        ).toBeInTheDocument();

        rerender(
            <PortalCancellationCard
                panel={panel({
                    cancel_url: null,
                    offered_end_dates: [],
                    earliest_end_on: null,
                    cancel_refusal: {
                        code: 'cancellation_no_dates_offered',
                        message: 'Synthetic: nothing to cancel.',
                    },
                })}
                serviceName="Web hosting"
            />,
        );
        expect(
            screen.getByText('Synthetic: nothing to cancel.'),
        ).toBeInTheDocument();
    });

    it('[CAN-4] shows a pending request and withdraws it only through the URL sent', async () => {
        const user = userEvent.setup();
        const pending = {
            id: 'request-1',
            requested_end_on: '2027-05-31',
            reason: 'Budget review.',
            requested_at: '2026-11-09T12:00:00Z',
        };
        const { rerender } = render(
            <PortalCancellationCard
                panel={panel({ pending_request: pending, cancel_url: null })}
                serviceName="Web hosting"
            />,
        );
        expect(
            screen.getByText('Cancellation requested · May 31, 2027'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        rerender(
            <PortalCancellationCard
                panel={panel({
                    pending_request: pending,
                    cancel_url: null,
                    withdraw_url: `${CANCEL}/withdraw`,
                })}
                serviceName="Web hosting"
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Withdraw request…' }),
        );
        await user.click(
            await screen.findByRole('button', { name: 'Withdraw request' }),
        );
        expect(router.post).toHaveBeenCalledWith(
            `${CANCEL}/withdraw`,
            {},
            expect.anything(),
        );
    });

    it('[EDGE-9] shows a scheduled end and offers undo only when the server does', async () => {
        const user = userEvent.setup();
        const scheduled = {
            id: 'end-1',
            ends_on: '2026-12-31',
            previous_ends_on: null,
            source: 'self_serve',
            scheduled_at: '2026-11-09T12:00:00Z',
        };
        const { rerender } = render(
            <PortalCancellationCard
                panel={panel({
                    scheduled_end: scheduled,
                    cancel_url: null,
                    cancelled_by_client: true,
                })}
                serviceName="Web hosting"
            />,
        );
        expect(screen.getByText('Ends Dec 31, 2026')).toBeInTheDocument();
        expect(screen.getByText(/Cancelled by you on/)).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        rerender(
            <PortalCancellationCard
                panel={panel({
                    scheduled_end: scheduled,
                    cancel_url: null,
                    cancelled_by_client: true,
                    undo_url: `${CANCEL}/undo`,
                })}
                serviceName="Web hosting"
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Undo cancellation…' }),
        );
        await user.click(
            await screen.findByRole('button', { name: 'Undo cancellation' }),
        );
        expect(router.post).toHaveBeenCalledWith(
            `${CANCEL}/undo`,
            {},
            expect.anything(),
        );
    });

    it('[CAN-4] reads out a refusal and keeps the dialog open', async () => {
        const user = userEvent.setup();
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onError?.({
                end_date: 'That end date is not one of the dates offered.',
            });
            options?.onFinish?.({} as never);
        });
        render(
            <PortalCancellationCard
                panel={panel()}
                serviceName="Web hosting"
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Request cancellation…' }),
        );
        await user.click(
            await screen.findByRole('button', { name: 'Send request' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'That end date is not one of the dates offered.',
        );
    });

    it('[CAN-1] words notice-only terms', () => {
        expect(
            cancellationTermsSentence({
                notice_days: 0,
                minimum_term_months: null,
                minimum_term_ends_on: null,
            }),
        ).toBe('No notice, ending at the close of a billing period');
    });
});
