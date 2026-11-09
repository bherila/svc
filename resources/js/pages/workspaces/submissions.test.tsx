import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Submissions from '@/pages/workspaces/submissions';
import Timesheet from '@/pages/workspaces/timesheet';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { SubmissionsProps, TimesheetProps } from '@/types/submission';

const api = vi.hoisted(() => ({ apiRequest: vi.fn() }));
const refresh = vi.hoisted(() => ({ refreshPageProps: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    apiRequest: api.apiRequest,
}));
vi.mock('@/lib/refresh-page-props', () => refresh);
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
    router: { visit: vi.fn() },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        }),
    }),
}));

const LONG = 'Unbroken'.repeat(12);

function submissions(
    overrides: Partial<SubmissionsProps['submissions']> = {},
): SubmissionsProps {
    return {
        workspace: { id: 'workspace-1', name: 'Synthetic Workspace' },
        submissions: {
            period: '2026-10',
            period_label: 'October 2026',
            previous_period: '2026-09',
            next_period: '2026-11',
            time: [
                {
                    id: 'time-1',
                    worked_on: '2026-10-14',
                    minutes: 90,
                    description: 'Internal note',
                    client_description: LONG,
                    project: LONG,
                    state: 'draft',
                    dispute_note: null,
                    can_submit: true,
                    why_not: null,
                    can_withdraw: false,
                },
                {
                    id: 'time-2',
                    worked_on: '2026-10-15',
                    minutes: 30,
                    description: 'Another',
                    client_description: 'Seen by client',
                    project: 'Synthetic Project',
                    state: 'submitted',
                    dispute_note: null,
                    can_submit: false,
                    why_not:
                        'This is already submitted. Withdraw it first to change it.',
                    can_withdraw: true,
                },
                {
                    id: 'time-3',
                    worked_on: '2026-10-16',
                    minutes: 15,
                    description: 'Private',
                    client_description: null,
                    project: 'Synthetic Project',
                    state: 'disputed',
                    dispute_note: 'Which ticket?',
                    can_submit: false,
                    why_not:
                        'Add the client-facing description and show it to the client before submitting: a reviewer reads only that.',
                    can_withdraw: false,
                },
            ],
            expenses: [],
            totals: {
                draft: 90,
                submitted: 30,
                approved: 0,
                disputed: 15,
                written_off: 0,
            },
            truncated: false,
            ...overrides,
        },
        links: {
            submit_url: '/api/v1/workspaces/workspace-1/submissions',
            withdraw_url: '/api/v1/workspaces/workspace-1/submissions/withdraw',
            previous_href: '/workspaces/workspace-1/submissions?period=2026-09',
            next_href: '/workspaces/workspace-1/submissions?period=2026-11',
            timesheet_href: '/workspaces/workspace-1/timesheet?period=2026-10',
            my_work_href: '/workspaces/workspace-1/my-work',
        },
    };
}

beforeEach(() => {
    api.apiRequest.mockReset();
    refresh.refreshPageProps.mockReset();
    refresh.refreshPageProps.mockImplementation(
        async ({ onFinish }: { onFinish?: () => void } = {}) => onFinish?.(),
    );
});

describe('Submit for review', () => {
    it('prints each state as words with its next step, never the stored value', () => {
        render(<Submissions {...submissions()} />);

        expect(screen.getByText('Draft')).toBeInTheDocument();
        expect(screen.getByText('Submitted')).toBeInTheDocument();
        expect(screen.getByText('Disputed')).toBeInTheDocument();
        expect(screen.getByText('“Which ticket?”')).toBeInTheDocument();
        expect(screen.getByText(/Which ticket/)).toBeInTheDocument();
        expect(screen.getByText('October 2026')).toBeInTheDocument();
    });

    it('submits one row by id and the month by period, through the URLs the server sent', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: {} });
        render(<Submissions {...submissions()} />);

        await user.click(screen.getByRole('button', { name: /^Submit$/ }));
        await waitFor(() => expect(api.apiRequest).toHaveBeenCalledTimes(1));
        expect(api.apiRequest).toHaveBeenLastCalledWith(
            'POST',
            '/api/v1/workspaces/workspace-1/submissions',
            { time_entry_ids: ['time-1'] },
        );

        await user.click(
            screen.getByRole('button', { name: 'Submit October 2026' }),
        );
        await waitFor(() => expect(api.apiRequest).toHaveBeenCalledTimes(2));
        expect(api.apiRequest).toHaveBeenLastCalledWith(
            'POST',
            '/api/v1/workspaces/workspace-1/submissions',
            { period: '2026-10' },
        );
        expect(refresh.refreshPageProps).toHaveBeenCalled();
    });

    it('withdraws a submitted row and says why a row cannot be submitted', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: {} });
        render(<Submissions {...submissions()} />);

        await user.click(screen.getByRole('button', { name: /^Withdraw$/ }));
        await waitFor(() => expect(api.apiRequest).toHaveBeenCalledTimes(1));
        expect(api.apiRequest).toHaveBeenLastCalledWith(
            'POST',
            '/api/v1/workspaces/workspace-1/submissions/withdraw',
            { time_entry_ids: ['time-2'] },
        );
        expect(
            screen.getByText(/Add the client-facing description/),
        ).toBeInTheDocument();
    });

    it('shows the server refusal and leaves the page alone', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: false,
            status: 409,
            message: 'This is already submitted.',
        });
        render(<Submissions {...submissions()} />);

        await user.click(screen.getByRole('button', { name: /^Submit$/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This is already submitted.',
        );
        expect(refresh.refreshPageProps).not.toHaveBeenCalled();
    });

    it('disables the month controls when nothing can move and links the neighbouring months', () => {
        render(
            <Submissions
                {...submissions({
                    time: [],
                    totals: {
                        draft: 0,
                        submitted: 0,
                        approved: 0,
                        disputed: 0,
                        written_off: 0,
                    },
                })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Submit October 2026' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Withdraw October 2026' }),
        ).toBeDisabled();
        expect(screen.getByRole('link', { name: /Previous/ })).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/submissions?period=2026-09',
        );
        expect(
            screen.getByRole('link', { name: 'Log some on My work' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/my-work');
    });

    it('has no horizontal overflow with unbroken names', () => {
        const { container } = render(<Submissions {...submissions()} />);

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

function sheet(): TimesheetProps {
    return {
        workspace: { id: 'workspace-1', name: 'Synthetic Workspace' },
        timesheet: {
            period: '2026-10',
            period_label: 'October 2026',
            previous_period: '2026-09',
            next_period: '2026-11',
            entries: [
                {
                    id: 'time-1',
                    worked_on: '2026-10-14',
                    minutes: 90,
                    description: LONG,
                    project: LONG,
                    task: LONG,
                    worked_by: LONG,
                    approved_by: LONG,
                    approved_at: '2026-11-02T10:00:00+00:00',
                    approved_via: 'Reviewer',
                },
            ],
            total_minutes: 90,
            truncated: false,
        },
        links: {
            previous_href: '/workspaces/workspace-1/timesheet?period=2026-09',
            next_href: '/workspaces/workspace-1/timesheet?period=2026-11',
            csv_href:
                '/workspaces/workspace-1/timesheet/export?period=2026-10&format=csv',
            pdf_href:
                '/workspaces/workspace-1/timesheet/export?period=2026-10&format=pdf',
            submissions_href:
                '/workspaces/workspace-1/submissions?period=2026-10',
        },
    };
}

describe('Approved timesheet', () => {
    it('lists who approved each entry and offers both downloads from the server URLs', () => {
        render(<Timesheet {...sheet()} />);

        const row = screen.getAllByRole('row')[1];
        expect(within(row).getByText(/Reviewer, /)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Download CSV/ }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/timesheet/export?period=2026-10&format=csv',
        );
        expect(
            screen.getByRole('link', { name: /Download PDF/ }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/timesheet/export?period=2026-10&format=pdf',
        );
    });

    it('says when nothing was approved', () => {
        const props = sheet();
        props.timesheet.entries = [];
        props.timesheet.total_minutes = 0;
        render(<Timesheet {...props} />);

        expect(
            screen.getByText(/Nothing was approved in October 2026/),
        ).toBeInTheDocument();
    });

    it('has no horizontal overflow with unbroken names', () => {
        const { container } = render(<Timesheet {...sheet()} />);

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
