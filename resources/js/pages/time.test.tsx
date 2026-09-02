import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TimeSheet from '@/pages/time';
import { sharedPageProps } from '@/test/shared-page-props';
import type { ClientContext } from '@/types/navigation';
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

let clientContext: ClientContext | null = null;

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: inertia,
    // The sheet renders inside the client chrome on the company tab and bare
    // on the workspace-wide route. A null context is the latter, which keeps
    // these tests about the sheet's own controls - the chrome has its own
    // tests in `client-context-layout.test.tsx`.
    usePage: () => ({ props: sharedPageProps({ clientContext }) }),
}));

beforeEach(() => {
    clientContext = null;
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
    it('renders one appearance selector on the workspace-wide route', () => {
        render(<TimeSheet {...props()} />);

        expect(
            screen.getAllByRole('combobox', { name: 'Appearance' }),
        ).toHaveLength(1);
    });

    it('renders one appearance selector on the client-scoped route', () => {
        clientContext = {
            workspace: {
                id: 'workspace-1',
                name: 'Synthetic Workspace',
            },
            companies: [
                { id: 'company-1', name: 'Synthetic Client' },
                { id: 'company-2', name: 'Another Synthetic Client' },
            ],
            current_company_id: 'company-1',
            can_manage: true,
        };

        render(<TimeSheet {...props()} />);

        expect(
            screen.getAllByRole('combobox', { name: 'Appearance' }),
        ).toHaveLength(1);
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
