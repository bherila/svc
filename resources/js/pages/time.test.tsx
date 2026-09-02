import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TimeSheet from '@/pages/time';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { WorkspaceNavigation } from '@/types/navigation';
import type {
    CompanyOption,
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

function props({
    canLogTime = true,
    timeEntry = entry(),
}: {
    canLogTime?: boolean;
    timeEntry?: TimeEntry;
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
        months: [
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
