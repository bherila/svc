import { Link } from '@inertiajs/react';
import { AppearanceSelector } from '@/components/appearance-selector';
import { CommandPaletteTrigger } from '@/components/command-palette';
import { AccountMenu } from '@/components/navigation/account-menu';
import { ClientCompanySwitcher } from '@/components/navigation/client-company-switcher';
import { ClientModuleTabs } from '@/components/navigation/client-module-tabs';
import type { ClientModule, WorkspaceNavigation } from '@/types/navigation';

/**
 * One row, read left to right as a sentence about where the reader is.
 *
 *     SVC  [Client ▾]  Client Home  Invoices  Time  Expenses  Tasks    ⌘K  ☾  ⚙
 *
 * The wordmark first, because it is the only intentional way back out - it
 * returns to the workspace selector, and nothing else in the application
 * changes workspace. Then the client, then that client's modules; then, pushed
 * to the far end, the things that mean the same thing whatever the client is.
 * The boundary between "the client I am in" and "me, and the rest of the
 * system" is a position on the row rather than something to infer from labels.
 *
 * What is deliberately absent: the workspace's name. Naming the tenant beside
 * the client offers a second context to read the tabs against, and the payload
 * does not even carry it. Nor is there a client directory or an Operations
 * link - each was another route to things the tabs already reach, and having
 * two is what made getting anywhere take four clicks and a guess.
 *
 * The row never wraps. Wrapping turns one bar into two and moves the tabs to
 * where the reader has stopped looking; instead the tab strip scrolls, and the
 * two anchors - the wordmark and the switcher - are the parts that never shrink
 * away.
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
            <div className="flex h-12 w-full items-center gap-2 px-4">
                <Link
                    href="/app"
                    aria-label="Choose workspace"
                    className="shrink-0 rounded-lg px-1 text-base font-bold tracking-tight focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    SVC
                </Link>

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
                    <CommandPaletteTrigger />
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
