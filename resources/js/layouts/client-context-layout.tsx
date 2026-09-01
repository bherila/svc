import { Link, router, usePage } from '@inertiajs/react';
import { AppearanceSelector } from '@/components/appearance-selector';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ClientContext, ClientTab } from '@/types/navigation';

/**
 * The chrome every client screen sits inside.
 *
 * One company is the context, and it is chosen once - top left, persistently -
 * rather than re-picked on each screen. Everything below is a tab of that
 * company: Overview, Tasks, Time, Invoices, and Manage when the operator may
 * manage it. Manage is a tab and not a separate section on purpose; a parallel
 * "admin" copy of a module is how two views of the same thing drift apart.
 *
 * Invoices and agreements belong to the company rather than to a project, while
 * tasks and time belong to a project - so the company is what the route
 * carries, and the project is a filter inside the tabs that have one. Making
 * the project the context instead would have forced Invoices to become a
 * derived view of whichever invoice lines happened to touch that project.
 */

/** Tabs in the order an operator reads them, not the order they were built. */
const TABS: readonly ClientTab[] = [
    { key: 'overview', label: 'Overview', segment: '' },
    { key: 'tasks', label: 'Tasks', segment: 'tasks' },
    { key: 'time', label: 'Time', segment: 'time' },
    { key: 'invoices', label: 'Invoices', segment: 'invoices' },
    { key: 'manage', label: 'Manage', segment: 'manage' },
];

/**
 * The tabs that have a page behind them.
 *
 * One list rather than a prop each page supplies, because pages that each
 * declare the tab set drift: the strip would differ depending on which tab you
 * were standing on. Adding a tab is one edit here, in the commit that adds its
 * route.
 */
const IMPLEMENTED_TABS: readonly ClientTab['key'][] = [
    'overview',
    'tasks',
    'time',
    'invoices',
    'manage',
];

/** Tabs that appear only for a viewer who may manage the workspace. */
const MANAGE_ONLY: readonly ClientTab['key'][] = ['manage'];

function tabHref(
    workspaceId: string,
    companyId: string,
    segment: string,
): string {
    const base = `/workspaces/${workspaceId}/clients/${companyId}`;

    return segment === '' ? base : `${base}/${segment}`;
}

export default function ClientContextLayout({
    active,
    available = IMPLEMENTED_TABS,
    children,
}: {
    /** Which tab this page is. */
    active: ClientTab['key'];
    /**
     * Overridable only for tests. Pages take the shared list, so the strip
     * cannot link to a route nobody has built and cannot differ between tabs.
     */
    available?: readonly ClientTab['key'][];
    children: React.ReactNode;
}) {
    const context = usePage().props.clientContext as ClientContext | null;
    const companyId = context?.current_company_id ?? null;

    // Off a workspace route there is no company to be inside, so the chrome
    // renders nothing rather than an empty switcher. Narrowed through a local
    // rather than the property, so the value stays a string inside the
    // callbacks below.
    if (context === null || companyId === null) {
        return <>{children}</>;
    }

    const workspaceId = context.workspace.id;
    const visible = TABS.filter(
        (tab) =>
            available.includes(tab.key) &&
            (context.can_manage || !MANAGE_ONLY.includes(tab.key)),
    );

    return (
        <div className="min-h-screen" data-appearance-bridge>
            <div className="border-b">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-3 px-6 py-3">
                    <Select
                        value={companyId}
                        onValueChange={(next) => {
                            // Base UI can emit null when a select is cleared,
                            // which is not a client to switch to.
                            if (
                                typeof next === 'string' &&
                                next !== companyId
                            ) {
                                // The same tab on the newly chosen company, so
                                // switching client keeps the operator where
                                // they were rather than sending them home.
                                const segment =
                                    TABS.find((tab) => tab.key === active)
                                        ?.segment ?? '';
                                router.visit(
                                    tabHref(workspaceId, next, segment),
                                );
                            }
                        }}
                    >
                        <SelectTrigger
                            className="w-64"
                            aria-label="Current client"
                        >
                            <SelectValue placeholder="Select a client" />
                        </SelectTrigger>
                        <SelectContent>
                            {context.companies.map((company) => (
                                <SelectItem key={company.id} value={company.id}>
                                    {company.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Link
                        href={`/workspaces/${workspaceId}/clients`}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        All clients
                    </Link>
                    <div className="ml-auto">
                        <AppearanceSelector />
                    </div>
                </div>

                <nav
                    aria-label="Client sections"
                    className="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-6"
                >
                    {visible.map((tab) => (
                        <Link
                            key={tab.key}
                            href={tabHref(workspaceId, companyId, tab.segment)}
                            aria-current={
                                tab.key === active ? 'page' : undefined
                            }
                            className={
                                tab.key === active
                                    ? 'border-b-2 border-foreground px-3 py-2 text-sm font-medium'
                                    : 'border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground hover:text-foreground'
                            }
                        >
                            {tab.label}
                        </Link>
                    ))}
                </nav>
            </div>

            {children}
        </div>
    );
}
