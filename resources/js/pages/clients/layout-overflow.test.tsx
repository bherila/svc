import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientHome from '@/pages/clients/home';
import ClientInvoices from '@/pages/clients/invoices';
import ClientTasks from '@/pages/clients/tasks';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { clientOption, workspaceNavigation } from '@/test/workspace-navigation';

/**
 * The client tabs, rendered against data that does not fit.
 *
 * Client Home shipped scrolling sideways for a client whose time entries were
 * real ones - a week of SOC 2 notes with issue numbers after them - while every
 * fixture in the suite was three words long. The screens all sit on one shell
 * and were all built from the same handful of layout utilities, so the fault
 * was never one page's: it was a container shape repeated across the module,
 * and the only fixture that would have caught it is one deliberately too big
 * for the box.
 *
 * So the data here is hostile on purpose - a client name with no space to break
 * at, an identifier nobody would type, a description that runs for a paragraph,
 * a URL in a task title - and each page is asked the same question. What is
 * being checked and why it cannot simply be measured is in
 * `@/test/horizontal-overflow`.
 */

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
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                clients: [clientOption('company-1', LONG_NAME)],
            }),
        }),
    }),
}));

/** A registered company name with nowhere to break: 63 characters, one word. */
const LONG_NAME =
    'WolfeschlegelsteinhausenbergerdorffVeterinaryDiagnosticsGlobalAG';

const LONG_DESCRIPTION =
    'SOC 2 / Vanta (Week 35, 08-24 to 08-30): policy approvals completed, control and automated-test progress, root lockout found and filed. Issues: #2541, #2747, #2213, #2214, #949, #2748';

/** An identifier from an import, which is where the unreasonable ones come from. */
const LONG_INVOICE_NUMBER = 'VETV-202608-0000000000000000000001-RECONCILIATION';

const LONG_TASK_TITLE =
    'Investigate https://tracker.example.test/very/long/path/that/never/breaks/anywhere/2541';

const company = { id: 'company-1', name: LONG_NAME };

describe('client screens under data that does not fit', () => {
    it('keeps client home inside the window', () => {
        const { container } = render(
            <ClientHome
                company={company}
                latest_invoice={{
                    id: 'invoice-1',
                    invoice_number: LONG_INVOICE_NUMBER,
                    status: 'issued',
                    currency: 'USD',
                    issue_date: '2026-08-02',
                    due_date: '2026-09-01',
                    total_amount: 375000,
                    paid_amount: 0,
                    balance_amount: 375000,
                    href: '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
                }}
                recent_time={[
                    {
                        id: 'entry-1',
                        worked_on: '2026-08-30',
                        project: LONG_NAME,
                        description: LONG_DESCRIPTION,
                        minutes: 240,
                    },
                ]}
                open_tasks={[
                    {
                        id: 'task-1',
                        title: LONG_TASK_TITLE,
                        project: LONG_NAME,
                        status: 'in_progress',
                    },
                ]}
                engagement={{
                    agreement_title: LONG_DESCRIPTION,
                    agreement_status: 'active',
                    agreement_cadence: 'monthly',
                    agreement_href:
                        '/workspaces/workspace-1/clients/company-1/agreements/agreement-1',
                    proposal_title: LONG_DESCRIPTION,
                    proposal_status: 'sent',
                    proposal_href:
                        '/workspaces/workspace-1/clients/company-1/proposals/proposal-1',
                }}
                links={{
                    invoices:
                        '/workspaces/workspace-1/clients/company-1/invoices',
                    time: '/workspaces/workspace-1/clients/company-1/time',
                    tasks: '/workspaces/workspace-1/clients/company-1/tasks',
                }}
                settings_href="/workspaces/workspace-1/clients/company-1/settings"
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('keeps the invoice list inside the window', () => {
        const { container } = render(
            <ClientInvoices
                company={company}
                invoice_base_href="/workspaces/workspace-1/clients/company-1/invoices"
                invoices={[
                    {
                        id: 'invoice-1',
                        invoice_number: LONG_INVOICE_NUMBER,
                        status: 'issued',
                        currency: 'USD',
                        issue_date: '2026-08-02',
                        due_date: '2026-09-01',
                        total_amount: 375000,
                        paid_amount: 0,
                        balance_amount: 375000,
                    },
                ]}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('keeps the task list inside the window', () => {
        const { container } = render(
            <ClientTasks
                company={company}
                audience="operator"
                filters={{ project_id: null }}
                projects={[
                    { id: 'project-1', name: LONG_NAME },
                    { id: 'project-2', name: 'Second project' },
                ]}
                tasks={[
                    {
                        id: 'task-1',
                        title: LONG_TASK_TITLE,
                        status: 'in_progress',
                        project: LONG_NAME,
                        is_visible_to_client: true,
                        completed_at: null,
                    },
                ]}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    /**
     * The bar is the one thing on every screen, and the client name on it is
     * the longest string it has to hold. It is checked through a page rather
     * than on its own because the bar has no width of its own to overflow: what
     * matters is that it stays a row on a page that still fits.
     */
    it('keeps the navbar inside the window when the client has a long name', () => {
        const { container } = render(
            <ClientInvoices
                company={company}
                invoice_base_href="/workspaces/workspace-1/clients/company-1/invoices"
                invoices={[]}
            />,
        );

        const navbar = container.querySelector('header');

        expect(navbar).not.toBeNull();
        expect(navbar?.textContent).toContain(LONG_NAME);
        expect(horizontalOverflowRisks(navbar as HTMLElement)).toEqual([]);
    });
});
