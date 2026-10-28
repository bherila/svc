import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppearanceSelector } from '@/components/appearance-selector';
import { AccountMenu } from '@/components/navigation/account-menu';
import { NewClientDialog } from '@/components/navigation/new-client-dialog';
import { WorkspaceNavbar } from '@/components/navigation/workspace-navbar';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { ClientModule, WorkspaceNavigation } from '@/types/navigation';

/**
 * The chrome every page inside an entered workspace sits in.
 *
 * One shell for both populations. An operator and an external portal user see
 * the same bar, the same switcher and the same tabs; what differs is which
 * companies the switcher offers and which URLs their modules point at, and both
 * of those were decided on the server before this rendered. Sharing the shell
 * is what makes the two surfaces feel like one application; sharing the
 * *queries* is what would make the portal a way past the operator scoping, so
 * that is where the two stay apart.
 *
 * The bar renders even when no client is selected. Its predecessor returned the
 * page bare in that state, which meant the one screen where a reader most needs
 * the switcher - the one asking them to choose - was the screen with no
 * switcher on it.
 *
 * Pages take `activeModule` rather than deriving it from the URL, so a detail
 * screen keeps its owning tab lit: an invoice is Invoices, a project or an
 * agreement is Client Home.
 */
export default function WorkspaceShell({
    activeModule = 'home',
    selector = false,
    children,
}: {
    activeModule?: ClientModule;
    selector?: boolean;
    children: React.ReactNode;
}) {
    const navigation = usePage().props
        .workspaceNavigation as WorkspaceNavigation | null;
    const [creating, setCreating] = useState(false);

    if (selector) {
        return (
            <div
                className="min-h-screen bg-background text-foreground"
                data-appearance-bridge
            >
                <header className="border-b border-border">
                    <div
                        className={cn(
                            SHELL_CONTAINER,
                            'flex h-12 items-center gap-2',
                        )}
                    >
                        <span className="px-1 text-base font-bold tracking-tight">
                            SVC
                        </span>
                        <div className="ms-auto flex items-center gap-1">
                            <AppearanceSelector />
                            <AccountMenu />
                        </div>
                    </div>
                </header>
                {children}
            </div>
        );
    }

    // Off a workspace route entirely - the selector, the welcome page - there
    // is no workspace to be inside and nothing to render around the page.
    if (navigation === null || navigation === undefined) {
        return <>{children}</>;
    }

    return (
        <div
            className="min-h-screen bg-background text-foreground"
            data-appearance-bridge
        >
            <WorkspaceNavbar
                navigation={navigation}
                activeModule={activeModule}
                onCreateClient={() => setCreating(true)}
            />

            {children}

            {navigation.permissions.create_client && (
                <NewClientDialog
                    workspaceId={navigation.workspace_id}
                    open={creating}
                    onOpenChange={setCreating}
                />
            )}
        </div>
    );
}
