import { Link } from '@inertiajs/react';
import { ChevronRightIcon, LogOutIcon } from 'lucide-react';
import { AppearanceSelector } from '@/components/appearance-selector';
import { CommandPaletteTrigger } from '@/components/command-palette';
import { HelpButton } from '@/components/help/help-button';
import { AccountMenu } from '@/components/navigation/account-menu';
import { ClientCompanySwitcher } from '@/components/navigation/client-company-switcher';
import { ClientMenu } from '@/components/navigation/client-menu';
import {
    ClientModuleTabs,
    WorkspaceSectionTabs,
} from '@/components/navigation/section-tabs';
import { buttonVariants } from '@/components/ui/button';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type {
    ClientModule,
    WorkspaceNavigation,
    WorkspaceSection,
} from '@/types/navigation';

/**
 * One row, in two tiers, read left to right as a sentence about where you are.
 *
 *     ⏻ Workspace   Dashboard  Clients  Invoices  Time  Finance         ⌕ ? ☾ ⚙
 *     ⏻ Workspace › Acme ▾ ⋯   Overview  Agreements  Invoices  Time  Tasks  Files   ⌕ ? ☾ ⚙
 *
 * The workspace tier is the row with no client selected: the things that span
 * every client. Picking a client (from the Clients page or the switcher) drops
 * into the client tier, where the same row names the client and offers its own
 * six tabs; the switcher's "All clients" entry and the workspace name both lead
 * back up. Which tabs exist is decided on the server per role and arrives as
 * finished URLs - a null is a tab that is not rendered.
 *
 * The exit control first, then the workspace it leaves. This was an "SVC"
 * wordmark that quietly returned to the workspace selector, which failed twice
 * over: nothing on the screen said which tenant you were in, and the one way
 * out gave no sign of leading anywhere.
 *
 * The row never wraps, and it sits in the same column as the page below it.
 * What gives ground, in order: the workspace name (it truncates, and below `sm`
 * it is gone - the exit button stays, because losing the way out is worse than
 * losing the label); then the tab strip, which scrolls and then collapses into
 * a menu naming the section you are in; search becomes an icon button, as does
 * help. The client switcher truncates its name but never disappears, because it
 * is the context everything to its right is scoped by.
 */
export function WorkspaceNavbar({
    navigation,
    activeModule,
    activeSection,
    onCreateClient,
}: {
    navigation: WorkspaceNavigation;
    activeModule: ClientModule;
    activeSection: WorkspaceSection | null;
    onCreateClient?: () => void;
}) {
    const current =
        navigation.clients.find(
            (client) => client.id === navigation.current_client_id,
        ) ?? null;
    const tier = navigation.workspace_destinations ?? null;
    const dashboardHref = tier?.dashboard ?? null;
    // The switcher is the way into a client for someone with no workspace tier
    // (a portal user); everyone else picks a client from the Clients tab, and
    // the switcher appears once one is picked.
    const showSwitcher = current !== null || dashboardHref === null;
    // Inside a client the row also carries six tabs, so the workspace name waits
    // for `xl`; with no client there is room for it from `sm`.
    const nameShows = current !== null ? 'xl:block' : 'sm:block';

    return (
        <header className="sticky top-0 z-40 border-b border-border bg-background">
            {/*
             * Border edge to edge, contents in the same column as the page.
             * A bar whose ends sit hard against the viewport while the content
             * below is centred somewhere narrower is what made this look like
             * everything had been pushed to the right.
             */}
            <div
                className={cn(SHELL_CONTAINER, 'flex h-12 items-center gap-2')}
            >
                <Link
                    href="/app"
                    aria-label="Leave this workspace"
                    className={cn(
                        buttonVariants({ variant: 'ghost', size: 'icon' }),
                        'shrink-0',
                    )}
                >
                    <LogOutIcon
                        aria-hidden="true"
                        className="size-4 rotate-180"
                    />
                </Link>
                {dashboardHref !== null ? (
                    <Link
                        href={dashboardHref}
                        className={cn(
                            'hidden max-w-32 shrink truncate text-sm font-semibold tracking-tight hover:underline',
                            nameShows,
                        )}
                        title={navigation.workspace_name}
                    >
                        {navigation.workspace_name}
                    </Link>
                ) : (
                    <span
                        // Truncates rather than wraps, and the full name is on
                        // the selector one click away. Hidden below `sm` so a
                        // long workspace name cannot squeeze the switcher off
                        // a phone.
                        className={cn(
                            'hidden max-w-32 shrink truncate text-sm font-semibold tracking-tight',
                            nameShows,
                        )}
                        title={navigation.workspace_name}
                    >
                        {navigation.workspace_name}
                    </span>
                )}

                {showSwitcher && (
                    <>
                        {dashboardHref !== null && (
                            <ChevronRightIcon
                                aria-hidden="true"
                                className={cn(
                                    'hidden size-3.5 shrink-0 text-muted-foreground',
                                    nameShows,
                                )}
                            />
                        )}
                        <ClientCompanySwitcher
                            clients={navigation.clients}
                            currentId={navigation.current_client_id}
                            module={activeModule}
                            allClientsHref={dashboardHref}
                            onCreateClient={
                                navigation.create_client_url
                                    ? onCreateClient
                                    : undefined
                            }
                        />
                    </>
                )}

                {current !== null ? (
                    <>
                        <ClientMenu
                            clientName={current.name}
                            destinations={current.destinations}
                        />
                        <ClientModuleTabs
                            active={activeModule}
                            destinations={current.destinations}
                        />
                    </>
                ) : (
                    tier !== null && (
                        <WorkspaceSectionTabs
                            active={activeSection}
                            destinations={tier}
                        />
                    )
                )}

                <div className="ms-auto flex shrink-0 items-center gap-1">
                    {navigation.permissions.search && (
                        <CommandPaletteTrigger compact={current !== null} />
                    )}
                    <HelpButton />
                    <AppearanceSelector />
                    <AccountMenu
                        workspaceSettingsHref={
                            navigation.permissions.manage_workspace
                                ? navigation.workspace_settings_href
                                : null
                        }
                    />
                </div>
            </div>
        </header>
    );
}
