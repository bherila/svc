import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TimeSheet from '@/pages/time';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { WorkspaceNavigation } from '@/types/navigation';
import type {
    Capacity,
    CompanyOption,
    Month,
    TimeEntry,
    TimeSheetProps,
} from '@/types/time-sheet';

const inertia = vi.hoisted(() => ({
    delete: vi.fn(),
    get: vi.fn(),
    patch: vi.fn(),
    post: vi.fn(),
    visit: vi.fn(),
}));

let navigation: WorkspaceNavigation | null = null;

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: inertia,
    // The shell owns the navbar, and `workspace-shell.test.tsx` owns the
    // tests for it. A null payload renders the sheet without chrome, which
    // keeps these about the sheet's own controls.
    usePage: () => ({
        props: sharedPageProps({ workspaceNavigation: navigation }),
    }),
}));

beforeEach(() => {
    navigation = null;
});

function entry(overrides: Partial<TimeEntry> = {}): TimeEntry {
    return {
        id: 'entry-1',
        version: 'version-1',
        worked_on: '2026-08-23',
        minutes: 60,
        description: 'Review implementation',
        client_visible_description: null,
        is_billable: true,
        is_deferred: false,
        is_visible_to_client: false,
        subcontractor_billing_mode: null,
        status: 'draft',
        project: { id: 'project-1', name: 'Main project' },
        task: null,
        worker: 'Synthetic Manager',
        invoice: null,
        can_edit: true,
        can_approve: true,
        ...overrides,
    };
}

function company(canLogTime = true): CompanyOption {
    return {
        id: 'company-1',
        name: 'Synthetic Client',
        projects: [
            {
                id: 'project-1',
                name: 'Main project',
                can_log_time: canLogTime,
                tasks: [],
            },
        ],
    };
}

function quietMonth(key: string, label: string, unusedHours = 0): Month {
    return {
        key,
        label,
        total_minutes: 0,
        billable_minutes: 0,
        deferred_minutes: 0,
        capacity:
            unusedHours === 0
                ? []
                : [
                      {
                          agreement: 'Synthetic Monthly Retainer',
                          cycle_start: `${key}-01`,
                          available_hours: unusedHours,
                          retainer_hours: unusedHours,
                          rollover_in_hours: 0,
                          expired_hours: 0,
                          rollover_months: null,
                          deficit_offset_hours: 0,
                          worked_hours: 0,
                          unused_hours: unusedHours,
                          over_hours: 0,
                          carried_deficit_hours: 0,
                          remaining_rollover: 0,
                          balance_hours: unusedHours,
                          billed_overage_hours: 0,
                          paid_hours: unusedHours,
                          pending_minutes: 0,
                      },
                  ],
        entries: [],
    };
}

/**
 * A worked month with a capacity strip, for the arithmetic the strip states.
 *
 * Defaults describe the plain case - ten hours sold, four worked, nothing
 * carried and nothing owed - so a test names only the figures it is about.
 */
function workedMonth(overrides: Partial<Capacity> = {}): Month {
    const capacity: Capacity = {
        agreement: 'Synthetic Monthly Retainer',
        cycle_start: '',
        available_hours: 10,
        retainer_hours: 10,
        rollover_in_hours: 0,
        expired_hours: 0,
        rollover_months: 2,
        deficit_offset_hours: 0,
        worked_hours: 4,
        unused_hours: 6,
        over_hours: 0,
        carried_deficit_hours: 0,
        remaining_rollover: 0,
        balance_hours: 6,
        billed_overage_hours: 0,
        paid_hours: 10,
        pending_minutes: 0,
        ...overrides,
    };

    return {
        key: '2026-07',
        label: 'July 2026',
        total_minutes: Math.round(capacity.worked_hours * 60),
        billable_minutes: Math.round(capacity.worked_hours * 60),
        deferred_minutes: 0,
        capacity: [capacity],
        entries: [entry({ worked_on: '2026-07-04' })],
    };
}

function props({
    canLogTime = true,
    timeEntry = entry(),
    months,
}: {
    canLogTime?: boolean;
    timeEntry?: TimeEntry;
    months?: Month[];
} = {}): TimeSheetProps {
    return {
        workspace: {
            id: 'workspace-1',
            name: 'Synthetic Workspace',
            default_currency: 'USD',
            timezone: 'America/Los_Angeles',
        },
        approval_limit: 50,
        filters: { company_id: 'company-1' },
        companies: [company(canLogTime)],
        months: months ?? [
            {
                key: '2026-08',
                label: 'August 2026',
                total_minutes: timeEntry.minutes,
                billable_minutes: timeEntry.minutes,
                deferred_minutes: 0,
                capacity: [],
                entries: [timeEntry],
            },
        ],
    };
}

describe('time sheet controls', () => {
    /**
     * The theme control, the palette trigger and the client switcher are the
     * navbar's, and the page used to render a second copy of each whenever it
     * decided it was on a chrome-less route. One owner means the count is one
     * wherever the sheet is mounted, rather than one or two depending on which
     * route reached it.
     */
    it.each([
        ['without the shell', null],
        ['inside the shell', workspaceNavigation()],
    ])('renders exactly one appearance selector %s', (_name, payload) => {
        navigation = payload;

        render(<TimeSheet {...props()} />);

        expect(
            screen.queryAllByRole('combobox', { name: 'Appearance' }),
        ).toHaveLength(payload === null ? 0 : 1);
    });

    /**
     * The route names the client and the navbar switches it. A second picker
     * here is how a screen ends up showing one client's time under another
     * client's name.
     */
    it('offers no client picker of its own', () => {
        navigation = workspaceNavigation();

        render(<TimeSheet {...props()} />);

        expect(
            screen.queryByRole('combobox', { name: /client/i }),
        ).not.toBeInTheDocument();
    });

    it.each([
        ['flat_hourly', 'Subcontractor · billed separately'],
        ['retainer', 'Subcontractor · retainer'],
        ['direct', 'Subcontractor · direct'],
    ] as const)(
        'labels %s subcontractor time by billing treatment',
        (mode, label) => {
            render(
                <TimeSheet
                    {...props({
                        timeEntry: entry({ subcontractor_billing_mode: mode }),
                    })}
                />,
            );

            expect(screen.getByText(label)).toBeInTheDocument();
        },
    );

    it('does not label a row when the financial mode snapshot is omitted', () => {
        render(
            <TimeSheet
                {...props({
                    timeEntry: entry({
                        subcontractor_billing_mode: undefined,
                    }),
                })}
            />,
        );

        expect(screen.queryByText(/Subcontractor ·/)).not.toBeInTheDocument();
    });

    it('keeps bulk approval single-flight until the request finishes', async () => {
        const user = userEvent.setup();
        render(<TimeSheet {...props()} />);

        await user.click(
            screen.getByRole('checkbox', {
                name: 'Select Review implementation',
            }),
        );

        const selection = screen.getByText(/1 selected/).parentElement;
        expect(selection).not.toBeNull();

        const bulkApprove = within(selection as HTMLElement).getByRole(
            'button',
            { name: 'Approve' },
        );
        const rowApprove = screen
            .getAllByRole('button', { name: 'Approve' })
            .find((button) => button !== bulkApprove);
        expect(rowApprove).toBeDefined();

        await user.click(bulkApprove);

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(bulkApprove).toBeDisabled();
        expect(rowApprove).toBeDisabled();

        await user.click(bulkApprove);
        await user.click(rowApprove as HTMLElement);

        expect(inertia.post).toHaveBeenCalledTimes(1);
    });

    it('keeps row approval single-flight until the request finishes', async () => {
        const user = userEvent.setup();
        render(<TimeSheet {...props()} />);
        const approve = screen.getByRole('button', { name: 'Approve' });

        await user.click(approve);

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(approve).toBeDisabled();

        await user.click(approve);

        expect(inertia.post).toHaveBeenCalledTimes(1);
    });

    it('keeps deletion single-flight until the request finishes', async () => {
        const user = userEvent.setup();
        render(<TimeSheet {...props()} />);

        await user.click(screen.getByRole('button', { name: 'Delete' }));

        const confirmation = await screen.findByRole('alertdialog');
        const remove = within(confirmation).getByRole('button', {
            name: 'Delete',
        });

        await user.click(remove);

        expect(inertia.delete).toHaveBeenCalledTimes(1);
        expect(remove).toBeDisabled();

        fireEvent.click(remove);

        expect(inertia.delete).toHaveBeenCalledTimes(1);
    });

    it('does not offer time logging when no project is loggable', () => {
        render(<TimeSheet {...props({ canLogTime: false })} />);

        expect(
            screen.queryByRole('button', { name: 'Log time' }),
        ).not.toBeInTheDocument();
    });
});

/**
 * The sheet's window is twelve months, and a client worked for two of them
 * produced ten full cards - heading, capacity strip, empty table - to scroll
 * past before reaching anything worth reading.
 */
describe('months with nothing logged', () => {
    it('folds a run of empty months into one block of rows', () => {
        render(
            <TimeSheet
                {...props({
                    months: [
                        quietMonth('2026-07', 'July 2026', 30),
                        quietMonth('2026-06', 'June 2026', 30),
                    ],
                })}
            />,
        );

        const quiet = screen.getByRole('region', {
            name: 'Months with no time logged',
        });

        expect(within(quiet).getAllByRole('listitem')).toHaveLength(2);
        expect(within(quiet).getByText('July 2026')).toBeInTheDocument();

        // No table, so no column headings to read past.
        expect(screen.queryByText('Work')).not.toBeInTheDocument();
    });

    /**
     * Folded, never dropped. "Did I log anything in April" is a question this
     * screen should answer, and a month that has silently disappeared cannot.
     */
    it('keeps an empty month in its place between two worked ones', () => {
        render(
            <TimeSheet
                {...props({
                    months: [
                        {
                            key: '2026-08',
                            label: 'August 2026',
                            total_minutes: 60,
                            billable_minutes: 60,
                            deferred_minutes: 0,
                            capacity: [],
                            entries: [entry()],
                        },
                        quietMonth('2026-07', 'July 2026'),
                        {
                            key: '2026-06',
                            label: 'June 2026',
                            total_minutes: 60,
                            billable_minutes: 60,
                            deferred_minutes: 0,
                            capacity: [],
                            entries: [entry({ id: 'entry-2' })],
                        },
                    ],
                })}
            />,
        );

        const labels = screen
            .getAllByText(/2026$/)
            .map((element) => element.textContent);

        expect(labels).toEqual(['August 2026', 'July 2026', 'June 2026']);
    });

    /**
     * The one thing an empty month still says: a retainer that sold thirty
     * hours and drew none is a fact about the engagement, not an absence of
     * one.
     */
    it('reports the retainer an empty month left unused', () => {
        render(
            <TimeSheet
                {...props({ months: [quietMonth('2026-07', 'July 2026', 30)] })}
            />,
        );

        expect(
            screen.getByText(/Nothing logged · 30\.00 h unused/),
        ).toBeInTheDocument();
    });

    it('says only that nothing was logged when there is no retainer', () => {
        render(
            <TimeSheet
                {...props({ months: [quietMonth('2026-07', 'July 2026')] })}
            />,
        );

        expect(screen.getByText('Nothing logged')).toBeInTheDocument();
    });
});

/**
 * The strip reported hours included, hours used and hours over, and left the
 * two questions an operator actually asks unanswered: where the engagement
 * stands overall, and how many of the hours worked have been paid for.
 */
describe('the capacity breakdown', () => {
    it('accounts for every hour between the grant and the availability', () => {
        render(
            <TimeSheet
                {...props({
                    months: [
                        workedMonth({
                            retainer_hours: 10,
                            deficit_offset_hours: 3,
                            rollover_in_hours: 5,
                            available_hours: 12,
                            worked_hours: 4,
                            unused_hours: 3,
                            remaining_rollover: 5,
                            balance_hours: 8,
                        }),
                    ],
                })}
            />,
        );

        const included = screen.getByText('Included this cycle');

        expect(included.nextSibling).toHaveTextContent('10.00 h');
        // The hours the client never got: an earlier overrun is repaid out of
        // this cycle's grant before any of it is available to work.
        expect(
            screen.getByText('Repaid earlier overrun').nextSibling,
        ).toHaveTextContent('\u22123.00 h');
        expect(screen.getByText('Carried in').nextSibling).toHaveTextContent(
            '5.00 h',
        );
        expect(screen.getByText('Available').nextSibling).toHaveTextContent(
            '12.00 h',
        );
    });

    /**
     * Unused hours, unspent carry-in and a deficit were three numbers on this
     * card, and no arrangement of them said whether the client was ahead of the
     * retainer or behind it.
     */
    it('states the carryover balance the cycle closes on', () => {
        render(
            <TimeSheet
                {...props({
                    months: [
                        workedMonth({
                            unused_hours: 3,
                            remaining_rollover: 5,
                            balance_hours: 8,
                        }),
                    ],
                })}
            />,
        );

        expect(
            screen.getByText('Carryover balance').nextSibling,
        ).toHaveTextContent('8.00 h');
        expect(
            screen.getByText(
                /Including 5\.00 h carried in from earlier cycles/,
            ),
        ).toBeInTheDocument();
    });

    it('shows a balance the client owes as a negative one', () => {
        render(
            <TimeSheet
                {...props({
                    months: [
                        workedMonth({
                            available_hours: 10,
                            worked_hours: 14,
                            unused_hours: 0,
                            over_hours: 4,
                            carried_deficit_hours: 6,
                            balance_hours: -6,
                        }),
                    ],
                })}
            />,
        );

        expect(
            screen.getByText('Carryover balance').nextSibling,
        ).toHaveTextContent('\u22126.00 h');
        // Four of those hours are this cycle's; the other two arrived owed.
        expect(
            screen.getByText(/Including 2\.00 h owed from earlier cycles/),
        ).toBeInTheDocument();
    });

    /**
     * Hours worked past the retainer and carried forward as a deficit are
     * unpaid; the same hours once invoiced are paid. A card reporting only
     * "included" and "over" draws them identically.
     */
    it('separates the hours paid for from the hours included', () => {
        render(
            <TimeSheet
                {...props({
                    months: [
                        workedMonth({
                            retainer_hours: 10,
                            worked_hours: 13,
                            unused_hours: 0,
                            over_hours: 3,
                            carried_deficit_hours: 0,
                            balance_hours: 0,
                            billed_overage_hours: 3,
                            paid_hours: 13,
                        }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('Paid for').nextSibling).toHaveTextContent(
            '13.00 h',
        );
        expect(
            screen.getByText('Billed at the hourly rate').nextSibling,
        ).toHaveTextContent('3.00 h');
    });

    it('names a negative charge as the correction it is', () => {
        render(
            <TimeSheet
                {...props({
                    months: [
                        workedMonth({
                            billed_overage_hours: -2,
                            paid_hours: 8,
                        }),
                    ],
                })}
            />,
        );

        expect(
            screen.getByText('Reversed by a correction').nextSibling,
        ).toHaveTextContent('\u22122.00 h');
    });
});

/**
 * The breakdown put a label and a figure on one row inside a card that is one
 * of several across the width of a month. jsdom measures nothing, so the check
 * is the shape: an agreement titled with an unbroken run has to have somewhere
 * for the overflow to go, or the card sizes the row and the row sizes the page.
 */
describe('the capacity breakdown under hostile data', () => {
    it('leaves nothing that can push the page sideways', () => {
        const { container } = render(
            <TimeSheet
                {...props({
                    months: [
                        workedMonth({
                            agreement:
                                'Synthetic-Retainer-Agreement-With-A-Title-Nobody-Would-Type-And-No-Spaces-In-It',
                            rollover_in_hours: 5,
                            deficit_offset_hours: 3,
                            expired_hours: 2,
                            billed_overage_hours: 3,
                            pending_minutes: 90,
                        }),
                    ],
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
