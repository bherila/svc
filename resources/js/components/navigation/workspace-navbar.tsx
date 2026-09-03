import { Link } from '@inertiajs/react';
import { LogOutIcon } from 'lucide-react';
import { AppearanceSelector } from '@/components/appearance-selector';
import { CommandPaletteTrigger } from '@/components/command-palette';
import { AccountMenu } from '@/components/navigation/account-menu';
import { ClientCompanySwitcher } from '@/components/navigation/client-company-switcher';
import { ClientModuleTabs } from '@/components/navigation/client-module-tabs';
import { Button } from '@/components/ui/button';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { ClientModule, WorkspaceNavigation } from '@/types/navigation';

/**
 * One row, read left to right as a sentence about where the reader is.
 *
 *     ⏻ Workspace  [Client ▾]  Client Home  Invoices  Time  Tasks    ⌘K  ☾  ⚙
 *
 * The exit control first, then the workspace it leaves. This was an "SVC"
 * wordmark that quietly returned to the workspace selector, which failed twice
 * over: nothing on the screen said which tenant you were in, and the one way
 * out was a piece of branding that gave no sign of leading anywhere. Naming the
 * workspace and putting a labelled exit beside it says both things in the space
 * the wordmark occupied.
 *
 * Then the client, then that client's modules; then, pushed to the far end, the
 * things that mean the same thing whatever the client is. The boundary between
 * "the client I am in" and "me, and the rest of the system" is a position on
 * the row rather than something to infer from labels. There is no client
 * directory or Operations link - each was another route to things the tabs
 * already reach, and having two is what made getting anywhere take four clicks
 * and a guess.
 *
 * The row never wraps, and it sits in the same column as the page below it.
 * Wrapping turns one bar into two and moves the tabs to where the reader has
 * stopped looking; instead the tab strip scrolls, and the two anchors - the
 * exit control and the switcher - are the parts that never leave.
 * The switcher does narrow, though: on a phone, a client whose registered name
 * runs to sixty characters would otherwise hold the row wider than the window
 * and scroll every page sideways, so it truncates the name rather than the bar
 * outgrowing the screen. The workspace name narrows the same way and, below the
 * `sm` breakpoint, gives up its text entirely - the exit button stays, because
 * losing the way out is worse than losing the label. The tenant is still named
 * on the selector that button leads to.
 */
export function WorkspaceNavbar({
    navigation,
    activeModule,
    onCreateClient,
}: {
    navigation: WorkspaceNavigation;
    activeModule: ClientModule;
    onCreateClient?: () => void;
}) {
    const current =
        navigation.clients.find(
            (client) => client.id === navigation.current_client_id,
        ) ?? null;

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
                <Button
                    variant="ghost"
                    size="icon"
                    className="shrink-0"
                    render={
                        <Link href="/app" aria-label="Leave this workspace">
                            {/*
                             * A door, not a sign-out. The account menu holds
                             * signing out; this leaves the tenant and returns
                             * to the selector, which is the only thing in the
                             * application that changes workspace.
                             */}
                            <LogOutIcon
                                aria-hidden="true"
                                className="size-4 rotate-180"
                            />
                        </Link>
                    }
                />

                <span
                    // Truncates rather than wraps, and the full name is on the
                    // selector one click away. Hidden below `sm` so a long
                    // workspace name cannot squeeze the switcher - which is the
                    // context everything right of it is scoped by - off a
                    // phone.
                    className="hidden max-w-40 shrink truncate text-sm font-semibold tracking-tight sm:block"
                    title={navigation.workspace_name}
                >
                    {navigation.workspace_name}
                </span>

                <ClientCompanySwitcher
                    clients={navigation.clients}
                    currentId={navigation.current_client_id}
                    module={activeModule}
                    onCreateClient={
                        navigation.permissions.create_client
                            ? onCreateClient
                            : undefined
                    }
                />

                {/*
                 * Only once a client is chosen. Before that there is nothing
                 * for the tabs to be tabs of, and the bar still renders - the
                 * reader can see where they are and switch - rather than the
                 * page appearing to have no chrome at all.
                 */}
                {current !== null && (
                    <ClientModuleTabs
                        active={activeModule}
                        destinations={current.destinations}
                    />
                )}

                <div className="ms-auto flex shrink-0 items-center gap-1">
                    {navigation.permissions.search && <CommandPaletteTrigger />}
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
