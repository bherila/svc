import { Head, Link, router } from '@inertiajs/react';
import { ChevronRightIcon, PlusIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

/**
 * Choose a workspace. That is the whole screen.
 *
 * What this replaced tried to be a dashboard: every client, every project and
 * every task of every workspace, plus four creation forms. It grew with the
 * account, so it got slower and harder to read the longer someone used it, and
 * none of it answered the question the page is actually asking.
 *
 * So: no nested clients, no projects, no tasks, no invoice or time summaries,
 * no role badges and no counts. Rows rather than cards, because a card implies
 * there is something to read on it and here there is only somewhere to go.
 * Everything else is one or two clicks further in, behind a navbar that is the
 * same on every screen.
 *
 * The bar here is a stub of that navbar - the wordmark, the theme control and
 * the account menu - because there is no workspace yet, so there is no client
 * to switch and no modules to tab between.
 */

type WorkspaceChoice = {
    id: string;
    name: string;
    enter_href: string;
};

function NewWorkspaceDialog({
    open,
    onOpenChange,
    createUrl,
}: {
    createUrl: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [name, setName] = useState('');
    const attempt = useRef<{
        name: string;
        url: string;
        savedUrl?: string;
        key: string;
        uncertain: boolean;
    } | null>(null);
    const [unresolved, setUnresolved] = useState(false);
    const [created, setCreated] = useState(false);
    const running = useRef(false);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!saving && !unresolved) {
            return;
        }

        const stopNavigation = router.on?.('before', (event) => {
            if (
                attempt.current !== null &&
                (attempt.current.savedUrl === undefined ||
                    new URL(event.detail.visit.url, window.location.href)
                        .href !==
                        new URL(attempt.current.savedUrl, window.location.href)
                            .href)
            ) {
                event.preventDefault();
                setError(
                    'Check the original workspace result before leaving this page.',
                );
            }
        });
        const stopUnload = (event: BeforeUnloadEvent) => {
            if (attempt.current !== null) {
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', stopUnload);

        return () => {
            stopNavigation?.();
            window.removeEventListener('beforeunload', stopUnload);
        };
    }, [saving, unresolved]);

    const close = (next: boolean) => {
        if (!running.current && !unresolved) {
            onOpenChange(next);
        }
    };
    async function create() {
        if (running.current) {
            return;
        }

        running.current = true;
        setSaving(true);
        setError(null);
        attempt.current ??= {
            name,
            url: createUrl,
            key: newIdempotencyKey(),
            uncertain: false,
        };
        const original = attempt.current;

        try {
            if (original.savedUrl === undefined) {
                const result = await apiRequest<
                    ApiSchema<'WorkspaceCreateResponse'>['data']
                >('POST', original.url, { name: original.name }, original.key);

                if (!result.ok) {
                    original.uncertain ||=
                        result.status === 0 ||
                        result.status === 408 ||
                        result.status >= 500;
                    setUnresolved(original.uncertain);

                    if (!original.uncertain) {
                        attempt.current = null;
                    }

                    setError(
                        Object.values(result.errors ?? {})
                            .flat()
                            .join(' ') || result.message,
                    );

                    return;
                }

                if (
                    !result.data ||
                    typeof result.data.web_url !== 'string' ||
                    result.data.web_url.trim() === ''
                ) {
                    original.uncertain = true;
                    setUnresolved(true);
                    setError(
                        'SVC’s response could not be read. Check the original creation.',
                    );

                    return;
                }

                original.savedUrl = result.data.web_url;
                setCreated(true);
                setUnresolved(true);
            }

            const destination = original.savedUrl;
            const navigated = await new Promise<boolean>((resolve) => {
                let settled = false;
                const finish = (success: boolean) => {
                    if (!settled) {
                        settled = true;
                        resolve(success);
                    }
                };
                router.visit(destination, {
                    onSuccess: () => finish(true),
                    onError: () => finish(false),
                    onCancel: () => finish(false),
                    onNetworkError: () => {
                        finish(false);

                        return false;
                    },
                    onHttpException: () => {
                        finish(false);

                        return false;
                    },
                    onFinish: () => finish(false),
                });
            });

            if (navigated) {
                attempt.current = null;
                setUnresolved(false);
                setCreated(false);
                onOpenChange(false);
            } else {
                setError(
                    'The workspace was created, but its page could not open. Open the created workspace to continue.',
                );
            }
        } catch {
            original.uncertain = true;
            setUnresolved(true);
            setError(
                original.savedUrl
                    ? 'The workspace was created, but its page could not open. Open the created workspace to continue.'
                    : 'The original workspace result could not be checked. Check the original creation before starting another.',
            );
        } finally {
            running.current = false;
            setSaving(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent
                className="sm:max-w-md"
                showCloseButton={!saving && !unresolved}
            >
                <DialogHeader>
                    <DialogTitle>New workspace</DialogTitle>
                    <DialogDescription>
                        You will be taken straight into it.
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="grid grid-cols-1 gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();

                        void create();
                    }}
                >
                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="new-workspace-name">Name</Label>
                        <Input
                            disabled={saving || unresolved}
                            id="new-workspace-name"
                            value={name}
                            required
                            maxLength={120}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </div>

                    {error !== null && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}

                    {unresolved && (
                        <p
                            role="status"
                            className="text-sm wrap-anywhere text-muted-foreground"
                        >
                            {created
                                ? 'The workspace is created. Open it before starting another creation.'
                                : 'The result is unknown. Check the original creation before editing again.'}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            type="button"
                            disabled={saving || unresolved}
                            onClick={() => close(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={saving || name === ''}>
                            {created
                                ? 'Open created workspace'
                                : unresolved
                                  ? 'Check creation'
                                  : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function WorkspaceSelector({
    workspaces,
    create_url: createUrl,
}: {
    create_url: string;
    workspaces: WorkspaceChoice[];
}) {
    const [creating, setCreating] = useState(false);

    return (
        <WorkspaceShell selector>
            <Head title="Choose a workspace" />

            <main className={cn(SHELL_CONTAINER, 'py-12')}>
                <div className="flex flex-wrap items-baseline justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Choose a workspace
                    </h1>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setCreating(true);
                        }}
                    >
                        <PlusIcon />
                        New workspace
                    </Button>
                </div>

                {workspaces.length === 0 ? (
                    <p className="mt-8 text-sm text-muted-foreground">
                        You are not in a workspace yet. Create one, or ask
                        someone to add you to theirs.
                    </p>
                ) : (
                    <ul className="mt-6 divide-y divide-border border-y border-border">
                        {workspaces.map((workspace) => (
                            <li key={workspace.id}>
                                <Link
                                    href={workspace.enter_href}
                                    className="flex items-center justify-between gap-4 px-1 py-3 text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="truncate font-medium">
                                        {workspace.name}
                                    </span>
                                    <span className="flex shrink-0 items-center gap-1 text-muted-foreground">
                                        Open
                                        <ChevronRightIcon
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </main>

            <NewWorkspaceDialog
                createUrl={createUrl}
                open={creating}
                onOpenChange={setCreating}
            />
        </WorkspaceShell>
    );
}
