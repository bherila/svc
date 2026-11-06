import { Head } from '@inertiajs/react';
import { ToApprove } from '@/components/portal/to-approve';
import { Card, CardContent } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { ApprovalsProps } from '@/types/review';

/**
 * "To approve" across every provider that lets this person decide
 * (docs/ux-overhaul.md sections 7c and 7d).
 *
 * One card for each provider, using the portal home's own layout. A provider
 * appears because the person holds a portal membership of its client or
 * because it gave them a reviewer grant; a revoked grant is simply gone. The
 * person belongs to none of these workspaces, so the page sits outside any
 * workspace and shows no workspace navigation.
 */
export default function Approvals({
    blocks,
    approval_result: result,
    workspaces_url: workspacesUrl,
}: ApprovalsProps) {
    return (
        <WorkspaceShell selector>
            <Head title="To approve" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <section className="grid grid-cols-1 gap-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        To approve
                    </h1>
                    <p className="text-muted-foreground">
                        Hours and expenses from each provider that asks you to
                        decide on them.
                    </p>
                </section>
                {blocks.length === 0 ? (
                    <Card data-test="nothing-to-approve">
                        <CardContent className="grid grid-cols-1 gap-2 text-sm">
                            <p>
                                No provider has asked you to approve anything.
                            </p>
                            <p className="text-muted-foreground">
                                A provider appears here once it gives you review
                                access, or once you are a billing contact of one
                                of its clients.
                            </p>
                            <p>
                                <a
                                    className="underline underline-offset-4"
                                    href={workspacesUrl}
                                >
                                    Back to your workspaces
                                </a>
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    blocks.map((block) => (
                        <ToApprove
                            key={`${block.source}:${block.key}`}
                            data={block.to_approve}
                            result={result?.key === block.key ? result : null}
                            title={block.provider}
                            caption={
                                block.source === 'grant'
                                    ? `Review access for ${block.client}`
                                    : `Billed to ${block.client}`
                            }
                            errorKey={`approval_${block.key}`}
                            empty={
                                block.source === 'grant'
                                    ? 'Nothing is waiting. Hours and expenses this provider has ready to bill appear here until you approve or dispute them.'
                                    : undefined
                            }
                        />
                    ))
                )}
            </main>
        </WorkspaceShell>
    );
}
