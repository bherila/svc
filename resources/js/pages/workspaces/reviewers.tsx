import { Head } from '@inertiajs/react';
import { GrantForm, GrantList } from '@/components/review/reviewer-grants';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { ReviewerGrantsProps } from '@/types/review';

/**
 * Where a provider decides who outside its team may vouch for its hours and
 * expenses (docs/ux-overhaul.md section 7d). Owners and admins only; the
 * server sends finished URLs and the choices it will accept.
 */
export default function WorkspaceReviewers({
    workspaceName,
    store_url: storeUrl,
    clients,
    workspaces,
    grants,
}: ReviewerGrantsProps) {
    return (
        <WorkspaceShell>
            <Head title="Reviewers" />
            <main className={cn(SHELL_CONTAINER, 'py-8')}>
                <div className="grid min-w-0 grid-cols-1 gap-6">
                    <section className="grid grid-cols-1 gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Reviewers
                        </h1>
                        <p className="wrap-anywhere text-muted-foreground">
                            Let a client’s person, or another workspace, approve
                            the hours and expenses {workspaceName} bills one
                            client for. Nothing is shared without a grant.
                        </p>
                    </section>
                    <GrantForm
                        storeUrl={storeUrl}
                        clients={clients}
                        workspaces={workspaces}
                    />
                    <GrantList grants={grants} />
                </div>
            </main>
        </WorkspaceShell>
    );
}
