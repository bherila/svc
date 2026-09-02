import { Head } from '@inertiajs/react';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

/**
 * Inside a workspace, not yet inside a client.
 *
 * Reached only when the entry point had no basis for choosing: several clients
 * are reachable and none has been opened before. It deliberately does not pick
 * the alphabetically first one - in an application about other people's money,
 * silently standing the reader inside the wrong client is worse than asking
 * them once.
 *
 * The shell renders above this, so the switcher that answers the question is
 * already on screen; this is the sentence telling the reader to use it.
 */
function message(hasClients: boolean, canCreateClient: boolean): string {
    if (hasClients) {
        return 'Choose a client company to continue.';
    }

    // An empty switcher cannot tell the reader which of these is true, and the
    // difference is the difference between "do something" and "wait".
    return canCreateClient
        ? 'This workspace has no clients yet. Add your first client from the switcher above.'
        : 'You do not have access to a client in this workspace yet. Ask a workspace manager to assign you one.';
}

export default function WorkspaceEntry({
    has_clients: hasClients,
    can_create_client: canCreateClient,
}: {
    has_clients: boolean;
    can_create_client: boolean;
}) {
    return (
        <WorkspaceShell>
            <Head title="Choose a client" />
            <main className={cn(SHELL_CONTAINER, 'py-16 text-center')}>
                <p className="text-sm text-muted-foreground">
                    {message(hasClients, canCreateClient)}
                </p>
            </main>
        </WorkspaceShell>
    );
}
