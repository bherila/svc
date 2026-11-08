import { Head, Link, router } from '@inertiajs/react';
import { CollaborationErrors } from '@/components/collaboration/content-controls';
import { useCollaborationMutation } from '@/components/collaboration/use-collaboration-mutation';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { OptionSelect } from '@/components/ui/option-select';
import { Textarea } from '@/components/ui/textarea';
import { useVersionedDraft } from '@/hooks/use-versioned-draft';
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
    const form = useVersionedDraft(
        {
            kind: 'note',
            title: '',
            body: '',
            project_id: scopes[0]?.id ?? '',
            is_visible_to_client: false as boolean,
        },
        'new-topic',
    );
    const mutation = useCollaborationMutation();

    return (
        <Card className="min-w-0">
            <CardContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        void mutation.run(
                            'POST',
                            href,
                            {
                                ...form.data,
                                project_id: form.data.project_id || null,
                            },
                            undefined,
                            true,
                        );
                    }}
                    className="grid grid-cols-1 gap-3"
                >
                    <h2 className="text-lg font-semibold">
                        Create a note or discussion
                    </h2>
                    <FormField label="Type">
                        {(id) => (
                            <OptionSelect
                                id={id}
                                disabled={mutation.locked}
                                value={form.data.kind}
                                options={[
                                    { value: 'note', label: 'Note' },
                                    {
                                        value: 'discussion',
                                        label: 'Discussion',
                                    },
                                ]}
                                onValueChange={(next) =>
                                    form.setData('kind', next)
                                }
                            />
                        )}
                    </FormField>
                    <FormField label="Scope">
                        {(id) => (
                            <OptionSelect
                                id={id}
                                disabled={mutation.locked}
                                value={form.data.project_id}
                                options={scopes.map((scope) => ({
                                    value: scope.id,
                                    label: scope.label,
                                }))}
                                onValueChange={(next) =>
                                    form.setData('project_id', next)
                                }
                            />
                        )}
                    </FormField>
                    <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                        Title
                        <Input
                            disabled={mutation.locked}
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
                            disabled={mutation.locked}
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
                            disabled={mutation.locked}
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
                    <CollaborationErrors errors={mutation.errors} />
                    <Button
                        type="submit"
                        disabled={mutation.pending}
                        className="justify-self-start"
                    >
                        {mutation.unresolved
                            ? 'Check original change'
                            : 'Create'}
                    </Button>
                </form>
            </CardContent>
        </Card>
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
                <FormField label="Show">
                    {(id) => (
                        <OptionSelect
                            id={id}
                            value={props.filters.kind ?? ''}
                            options={[
                                { value: '', label: 'Notes and discussions' },
                                { value: 'note', label: 'Notes' },
                                { value: 'discussion', label: 'Discussions' },
                            ]}
                            onValueChange={(next) =>
                                router.get(
                                    props.filter_href,
                                    {
                                        kind: next || null,
                                        project_id: props.filters.project_id,
                                    },
                                    { preserveState: true },
                                )
                            }
                        />
                    )}
                </FormField>
                <section
                    aria-label="Notes and discussions"
                    className="grid min-w-0 grid-cols-1 gap-3"
                >
                    {props.topics.length ? (
                        props.topics.map((topic) => (
                            <Card key={topic.id} className="min-w-0">
                                <CardContent>
                                    <article className="grid grid-cols-1 gap-1">
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
                                </CardContent>
                            </Card>
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
