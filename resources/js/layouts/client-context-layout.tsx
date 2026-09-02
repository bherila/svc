import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDownIcon, LayoutGridIcon, SettingsIcon } from 'lucide-react';
import { AppearanceSelector } from '@/components/appearance-selector';
import { CommandPaletteTrigger } from '@/components/command-palette';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
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
 *
 * The bar is one row, and the row is read left to right as a sentence about
 * where the reader is: *which client*, then *which part of that client*, then -
 * pushed to the far end - the things that are true regardless of the client.
 * Sign-out, the person's identity, the workspace-wide screens and the sibling
 * applications all belong in that last group, because none of them change when
 * the switcher does. Splitting the switcher onto its own row, which is what
 * this replaced, made the client look like a page heading rather than the
 * context the tabs hang off.
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
    const page = usePage();
    const context = page.props.clientContext as ClientContext | null;
    const auth = page.props.auth;
    const applications = page.props.applications;
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
    // The switcher names the current client, so it has to survive a company
    // that is in the route but not in the options - a scoped member reaching a
    // client through a direct link. Falling back to the workspace name would
    // claim the wrong thing, so it says only that something is selected.
    const currentCompany =
        context.companies.find((company) => company.id === companyId) ?? null;

    const switchTo = (next: string) => {
        if (next === companyId) {
            return;
        }

        // The same tab on the newly chosen company, so switching client keeps
        // the operator where they were rather than sending them home.
        const segment = TABS.find((tab) => tab.key === active)?.segment ?? '';
        router.visit(tabHref(workspaceId, next, segment));
    };

    return (
        <div className="min-h-screen" data-appearance-bridge>
            <header className="border-b bg-background">
                <div className="mx-auto flex h-12 max-w-6xl items-center gap-2 px-4">
                    {/*
                     * The client, as an app switcher rather than a form
                     * control. A select reads as a field the reader is
                     * expected to fill in; this is not input, it is the
                     * context everything to its right is scoped by, so it is
                     * shaped like the thing you switch between apps with.
                     */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="secondary"
                                size="sm"
                                aria-label="Current client"
                                className="max-w-56 shrink-0"
                            >
                                <LayoutGridIcon
                                    aria-hidden="true"
                                    className="size-3.5 opacity-70"
                                />
                                <span className="truncate font-semibold">
                                    {currentCompany?.name ?? 'Select a client'}
                                </span>
                                <ChevronsUpDownIcon
                                    aria-hidden="true"
                                    className="size-3.5 opacity-60"
                                />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="w-64">
                            <DropdownMenuGroup>
                                <DropdownMenuLabel>Clients</DropdownMenuLabel>
                                <DropdownMenuRadioGroup
                                    value={companyId}
                                    onValueChange={(next) => {
                                        // Base UI can emit null when a
                                        // selection is cleared, which is not a
                                        // client to switch to.
                                        if (typeof next === 'string') {
                                            switchTo(next);
                                        }
                                    }}
                                    aria-label="Current client"
                                >
                                    {context.companies.map((company) => (
                                        <DropdownMenuRadioItem
                                            key={company.id}
                                            value={company.id}
                                        >
                                            <span className="truncate">
                                                {company.name}
                                            </span>
                                        </DropdownMenuRadioItem>
                                    ))}
                                </DropdownMenuRadioGroup>
                            </DropdownMenuGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild>
                                <Link
                                    href={`/workspaces/${workspaceId}/clients`}
                                >
                                    All clients
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <CommandPaletteTrigger />

                    {/*
                     * The modules of the client named to the left, on the same
                     * row as the name they belong to.
                     */}
                    <nav
                        aria-label="Client sections"
                        className="flex min-w-0 items-center gap-0.5 overflow-x-auto"
                    >
                        {visible.map((tab) => (
                            <Link
                                key={tab.key}
                                href={tabHref(
                                    workspaceId,
                                    companyId,
                                    tab.segment,
                                )}
                                aria-current={
                                    tab.key === active ? 'page' : undefined
                                }
                                className={cn(
                                    'rounded-lg px-3 py-1.5 text-sm whitespace-nowrap transition-colors',
                                    tab.key === active
                                        ? 'bg-accent font-medium text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                )}
                            >
                                {tab.label}
                            </Link>
                        ))}
                    </nav>

                    {/*
                     * Everything that is not about this client. Pushed to the
                     * far end so the boundary between "the client I am in" and
                     * "me, and the rest of the system" is a position on the
                     * row rather than something the reader has to infer from
                     * the labels.
                     */}
                    <div className="ml-auto flex shrink-0 items-center gap-1">
                        <AppearanceSelector />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Account and settings"
                                >
                                    <SettingsIcon
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-60">
                                {/*
                                 * Who is signed in. The client screens have no
                                 * other chrome saying so, and the switcher to
                                 * the left can point at any client in the
                                 * workspace - so whose session this is, is
                                 * exactly the thing the reader cannot
                                 * otherwise check.
                                 */}
                                {auth.user !== null && (
                                    <>
                                        <DropdownMenuGroup>
                                            <DropdownMenuLabel>
                                                <span className="block truncate font-medium">
                                                    {auth.user.name}
                                                </span>
                                                <span className="block truncate text-xs font-normal text-muted-foreground">
                                                    {auth.user.email}
                                                </span>
                                            </DropdownMenuLabel>
                                        </DropdownMenuGroup>
                                        <DropdownMenuSeparator />
                                    </>
                                )}
                                <DropdownMenuGroup>
                                    <DropdownMenuLabel>
                                        {context.workspace.name}
                                    </DropdownMenuLabel>
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href={`/workspaces/${workspaceId}/clients`}
                                        >
                                            All clients
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href={`/workspaces/${workspaceId}/operations`}
                                        >
                                            Operations
                                        </Link>
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                                {/*
                                 * The sibling applications, as the identity
                                 * provider reported them at sign-in. Separated
                                 * from this workspace's own screens because
                                 * following one leaves the site, and because
                                 * the provider - not this bundle - decides
                                 * what is here.
                                 */}
                                {applications.length > 0 && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuGroup>
                                            <DropdownMenuLabel>
                                                Other apps
                                            </DropdownMenuLabel>
                                            {applications.map((app) => (
                                                <DropdownMenuItem
                                                    key={app.key}
                                                    asChild
                                                >
                                                    <a href={app.url}>
                                                        {app.name}
                                                    </a>
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuGroup>
                                    </>
                                )}
                                {auth.user !== null && (
                                    <>
                                        <DropdownMenuSeparator />
                                        {/*
                                         * A POST, because signing someone out
                                         * on a GET means any image tag on any
                                         * page can do it.
                                         */}
                                        <DropdownMenuItem asChild>
                                            <Link
                                                href="/logout"
                                                method="post"
                                                as="button"
                                                className="w-full justify-start"
                                            >
                                                Sign out
                                            </Link>
                                        </DropdownMenuItem>
                                    </>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </header>

            {children}
        </div>
    );
}
