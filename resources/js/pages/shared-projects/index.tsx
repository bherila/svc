import { Head, Link } from '@inertiajs/react';
import { ChevronRightIcon } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { SharedProjectIndexProps } from '@/types/shared-projects';

function Row({
    href,
    name,
    detail,
}: {
    href: string;
    name: string;
    detail: string;
}) {
    return (
        <li>
            <Link
                href={href}
                className="flex min-h-11 items-center gap-3 py-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span className="grid min-w-0 flex-1 grid-cols-1">
                    <span className="text-sm font-medium wrap-anywhere">
                        {name}
                    </span>
                    <span className="text-xs wrap-anywhere text-muted-foreground">
                        {detail}
                    </span>
                </span>
                <ChevronRightIcon
                    className="size-4 text-muted-foreground"
                    aria-hidden
                />
            </Link>
        </li>
    );
}

/** The shared projects a workspace hosts for others, and the ones it takes part in. */
export default function SharedProjectsIndex({
    hosted,
    participating,
}: SharedProjectIndexProps) {
    return (
        <WorkspaceShell>
            <Head title="Shared projects" />
            <main className={cn(SHELL_CONTAINER, 'py-8')}>
                <div className="grid min-w-0 grid-cols-1 gap-6">
                    <section className="grid grid-cols-1 gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Shared projects
                        </h1>
                        <p className="text-muted-foreground">
                            Projects several businesses work on together share
                            tasks and comments. Each business keeps its own
                            time, rates and invoices.
                        </p>
                    </section>
                    <Card data-test="hosted-projects">
                        <CardHeader>
                            <CardTitle>You host</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {hosted.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No project of yours is shared yet. Open a
                                    project and choose Sharing with other
                                    workspaces to invite another business.
                                </p>
                            ) : (
                                <ul className="grid grid-cols-1 divide-y divide-border">
                                    {hosted.map((project) => (
                                        <Row
                                            key={project.id}
                                            href={project.web_url}
                                            name={project.name}
                                            detail={project.client_name}
                                        />
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                    <Card data-test="participating-projects">
                        <CardHeader>
                            <CardTitle>You take part in</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {participating.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    You do not take part in anyone else’s
                                    project. When someone invites you, accept
                                    from the link they send.
                                </p>
                            ) : (
                                <ul className="grid grid-cols-1 divide-y divide-border">
                                    {participating.map((project) => (
                                        <Row
                                            key={project.id}
                                            href={project.web_url}
                                            name={project.name}
                                            detail={`Hosted by ${project.host_workspace_name}`}
                                        />
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </main>
        </WorkspaceShell>
    );
}
