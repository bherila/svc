import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ClientsTabs } from '@/components/packages/clients-tabs';
import { CopyLinkButton } from '@/components/packages/copy-link-button';
import {
    PackageEditorFields,
    buildBody,
    initialState,
} from '@/components/packages/package-editor';
import type { EditorState } from '@/components/packages/package-editor';
import { PackageMoves } from '@/components/packages/package-moves';
import { ConfirmedAction } from '@/components/shared-projects/participation-panels';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest } from '@/lib/api';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { cn } from '@/lib/utils';
import type { PackageEditProps } from '@/types/packages';

const STALE =
    'This package changed since you opened it. Reload to see the current version, then make your change again.';

function firstMessages(
    errors: Record<string, string[]> | undefined,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors ?? {}).flatMap(([key, messages]) =>
            messages[0] === undefined ? [] : [[key, messages[0]]],
        ),
    );
}

function Editor({
    package: pkg,
    moves,
    defaults,
    options,
    urls,
}: PackageEditProps) {
    const initial = useMemo(() => initialState(pkg, defaults), [pkg, defaults]);
    const [state, setState] = useState<EditorState>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [stale, setStale] = useState(false);
    const [busy, setBusy] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [retiring, setRetiring] = useState(false);

    const dirty = useMemo(
        () =>
            JSON.stringify(buildBody(state).body) !==
            JSON.stringify(buildBody(initial).body),
        [state, initial],
    );
    const refusals = pkg?.publish_refusals ?? [];
    const name = pkg?.draft?.terms.name ?? pkg?.published?.terms.name ?? '';
    const shownName = state.name.trim() === '' ? name : state.name;

    const refused = (status: number, text: string) => {
        if (status === 409) {
            setStale(true);

            return STALE;
        }

        return text;
    };

    const save = async () => {
        if (busy) {
            return;
        }

        const built = buildBody(state);
        setErrors(built.errors);
        setMessage(null);

        if (Object.keys(built.errors).length > 0) {
            setMessage('Fix the highlighted fields and save again.');

            return;
        }

        setBusy(true);
        const result =
            pkg === null
                ? await apiRequest('POST', urls.store ?? '', built.body)
                : await apiRequest('PATCH', urls.update ?? '', {
                      ...built.body,
                      expected_version: pkg.version,
                  });
        setBusy(false);

        if (!result.ok) {
            setErrors(firstMessages(result.errors));
            setMessage(refused(result.status, result.message));

            return;
        }

        if (pkg === null) {
            // The list carries each package's edit link, finished by the server.
            router.visit(urls.index);

            return;
        }

        await refreshPageProps();
    };

    const confirm = (url: string | null) => async () => {
        if (pkg === null || url === null) {
            return 'This action is not available.';
        }

        const result = await apiRequest('POST', url, {
            expected_version: pkg.version,
            confirm: true,
        });

        if (!result.ok) {
            return refused(result.status, result.message);
        }

        await refreshPageProps();

        return null;
    };

    const canPublish = urls.publish !== null && refusals.length === 0 && !dirty;

    return (
        <WorkspaceShell activeSection="clients">
            <Head title={pkg === null ? 'New package' : `Edit ${name}`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
                data-appearance-bridge
            >
                <ClientsTabs active="packages" />
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="grid min-w-0 grid-cols-1 gap-1">
                        <h1 className="text-[17px] font-semibold tracking-tight wrap-anywhere md:text-lg">
                            {pkg === null
                                ? 'New package'
                                : shownName === ''
                                  ? pkg.link_name
                                  : shownName}
                        </h1>
                        {pkg !== null && (
                            <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <Badge
                                    variant={
                                        pkg.status === 'published'
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {statusLabel(pkg.status)}
                                </Badge>
                                {pkg.published !== null && (
                                    <span>v{pkg.published.number} live</span>
                                )}
                                {pkg.draft !== null && (
                                    <span>
                                        editing v{pkg.draft.number} (draft)
                                    </span>
                                )}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {pkg?.public_url != null && (
                            <>
                                <a
                                    href={pkg.public_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={cn(
                                        buttonVariants({ variant: 'outline' }),
                                        'min-h-11 md:min-h-0',
                                    )}
                                >
                                    Preview page
                                </a>
                                <CopyLinkButton
                                    url={pkg.public_url}
                                    subject={shownName || pkg.link_name}
                                    size="default"
                                />
                            </>
                        )}
                        {urls.retire !== null && (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 md:min-h-0"
                                onClick={() => setRetiring(true)}
                            >
                                Retire…
                            </Button>
                        )}
                        {pkg !== null && urls.publish !== null && (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 md:min-h-0"
                                disabled={!canPublish}
                                onClick={() => setPublishing(true)}
                            >
                                Publish…
                            </Button>
                        )}
                        <Button
                            type="button"
                            className="min-h-11 md:min-h-0"
                            disabled={busy}
                            onClick={() => void save()}
                        >
                            {pkg === null ? 'Create draft' : 'Save draft'}
                        </Button>
                    </div>
                </div>

                {pkg !== null && pkg.draft === null && (
                    <p className="text-sm text-muted-foreground">
                        You are looking at the published terms. Saving a change
                        starts a new draft; the live version stays as it is
                        until you publish.
                    </p>
                )}
                {pkg !== null && dirty && urls.publish !== null && (
                    <p className="text-sm text-muted-foreground">
                        Save your changes before publishing.
                    </p>
                )}
                {refusals.length > 0 && (
                    <Card
                        role="group"
                        aria-label="Why this cannot be published yet"
                    >
                        <CardContent className="grid grid-cols-1 gap-1 text-sm">
                            <p className="font-medium">
                                Publish is unavailable until this is fixed:
                            </p>
                            <ul className="grid grid-cols-1 gap-1 text-muted-foreground">
                                {refusals.map((refusal) => (
                                    <li
                                        key={refusal.code}
                                        className="wrap-anywhere"
                                    >
                                        {refusal.message}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
                {message !== null && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center gap-3 text-sm wrap-anywhere text-destructive"
                    >
                        <span className="min-w-0">{message}</span>
                        {stale && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void refreshPageProps()}
                            >
                                Reload
                            </Button>
                        )}
                    </div>
                )}

                <PackageEditorFields
                    state={state}
                    onChange={setState}
                    options={options}
                    errors={errors}
                />

                {moves != null && <PackageMoves panel={moves} />}
            </main>

            {pkg !== null && urls.publish !== null && (
                <ConfirmedAction
                    open={publishing}
                    onOpenChange={setPublishing}
                    title={`Publish ${shownName || pkg.link_name}?`}
                    description="The draft becomes the live version. New sign-ups get these terms and its public page opens."
                    confirmLabel="Publish"
                    run={confirm(urls.publish)}
                />
            )}
            {pkg !== null && urls.retire !== null && (
                <ConfirmedAction
                    open={retiring}
                    onOpenChange={setRetiring}
                    title={`Retire ${shownName || pkg.link_name}?`}
                    description="It leaves the catalog and its public page closes to new sign-ups. Clients who already have it keep their terms."
                    confirmLabel="Retire"
                    run={confirm(urls.retire)}
                />
            )}
        </WorkspaceShell>
    );
}

export default function PackageEdit(props: PackageEditProps) {
    // A saved change reloads the page props; the form starts again from them.
    return <Editor key={props.package?.version ?? 'new'} {...props} />;
}
