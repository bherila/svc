import { Head, Link, router, useForm } from '@inertiajs/react';
import { CollaborationErrors } from '@/components/collaboration/content-controls';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { CollaborationIndexProps } from '@/types/collaboration';

function NewTopic({
    href,
    scopes,
}: {
    href: string;
    scopes: CollaborationIndexProps['create_scopes'];
}) {
    const form = useForm({
        kind: 'note',
        title: '',
        body: '',
        project_id: scopes[0]?.id ?? '',
        is_visible_to_client: false,
    });

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-3 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    project_id: data.project_id || null,
                }));
                form.post(href);
            }}
        >
            <h2 className="text-lg font-semibold">
                Create a note or discussion
            </h2>
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Type
                <select
                    className="min-w-0 rounded-md border bg-background p-2"
                    value={form.data.kind}
                    onChange={(event) =>
                        form.setData('kind', event.target.value)
                    }
                >
                    <option value="note">Note</option>
                    <option value="discussion">Discussion</option>
                </select>
            </label>
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Scope
                <select
                    className="min-w-0 rounded-md border bg-background p-2"
                    value={form.data.project_id}
                    onChange={(event) =>
                        form.setData('project_id', event.target.value)
                    }
                >
                    {scopes.map((scope) => (
                        <option key={scope.id} value={scope.id}>
                            {scope.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Title
                <Input
                    required
                    maxLength={255}
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
            </label>
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Content
                <Textarea
                    required
                    maxLength={50000}
                    className="min-h-48"
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                />
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={form.data.is_visible_to_client}
                    onChange={(event) =>
                        form.setData(
                            'is_visible_to_client',
                            event.target.checked,
                        )
                    }
                />
                Visible to client
            </label>
            <CollaborationErrors errors={form.errors} />
            <Button
                type="submit"
                disabled={form.processing}
                className="justify-self-start"
            >
                Create
            </Button>
        </form>
    );
}

export default function CollaborationIndex(props: CollaborationIndexProps) {
    return (
        <WorkspaceShell
            activeModule={
                props.filters.kind === 'discussion' ? 'discussions' : 'notes'
            }
        >
            <Head title={`${props.company.name} collaboration`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <h1 className="text-2xl font-semibold wrap-anywhere">
                    Notes and discussions
                </h1>
                <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                    Show
                    <select
                        className="min-w-0 rounded-md border bg-background p-2"
                        value={props.filters.kind ?? ''}
                        onChange={(event) =>
                            router.get(
                                props.filter_href,
                                {
                                    kind: event.target.value || null,
                                    project_id: props.filters.project_id,
                                },
                                { preserveState: true },
                            )
                        }
                    >
                        <option value="">Notes and discussions</option>
                        <option value="note">Notes</option>
                        <option value="discussion">Discussions</option>
                    </select>
                </label>
                <section
                    aria-label="Notes and discussions"
                    className="grid min-w-0 grid-cols-1 gap-3"
                >
                    {props.topics.length ? (
                        props.topics.map((topic) => (
                            <article
                                key={topic.id}
                                className="grid min-w-0 grid-cols-1 gap-1 rounded-lg border p-4"
                            >
                                <p className="text-sm text-muted-foreground">
                                    {topic.kind_label}
                                </p>
                                <p className="text-sm wrap-anywhere text-muted-foreground">
                                    {topic.scope_label}
                                </p>
                                <Link
                                    href={topic.href}
                                    className="font-medium wrap-anywhere underline"
                                >
                                    {topic.title}
                                </Link>
                                <p className="text-sm wrap-anywhere text-muted-foreground">
                                    {topic.author_name}
                                </p>
                            </article>
                        ))
                    ) : (
                        <p className="text-muted-foreground">
                            No notes or discussions yet.
                        </p>
                    )}
                </section>
                <nav aria-label="Topic pages" className="flex flex-wrap gap-4">
                    {props.previous_href && (
                        <Link className="underline" href={props.previous_href}>
                            Previous topics
                        </Link>
                    )}
                    {props.next_href && (
                        <Link className="underline" href={props.next_href}>
                            More topics
                        </Link>
                    )}
                </nav>
                {props.create_href && (
                    <NewTopic
                        href={props.create_href}
                        scopes={props.create_scopes}
                    />
                )}
            </main>
        </WorkspaceShell>
    );
}
