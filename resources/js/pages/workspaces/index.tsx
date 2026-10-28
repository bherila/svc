import { Head, Link, router } from '@inertiajs/react';
import { ChevronRightIcon, PlusIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
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
        key: string;
        uncertain: boolean;
    } | null>(null);
    const [unresolved, setUnresolved] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    async function create() {
        if (saving) {
            return;
        }

        setSaving(true);
        setError(null);
        attempt.current ??= {
            name,
            key: newIdempotencyKey(),
            uncertain: false,
        };
        const original = attempt.current;
        const result = await apiRequest<
            ApiSchema<'WorkspaceCreateResponse'>['data']
        >('POST', createUrl, { name: original.name }, original.key);

        if (!result.ok) {
            original.uncertain =
                result.status !== 422 &&
                (original.uncertain ||
                    result.status === 0 ||
                    result.status === 408 ||
                    result.status >= 500);
            setUnresolved(original.uncertain);

            if (!original.uncertain) {
                attempt.current = null;
            }

            setError(
                Object.values(result.errors ?? {})
                    .flat()
                    .join(' ') || result.message,
            );
            setSaving(false);

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
            setSaving(false);

            return;
        }

        router.visit(result.data.web_url);
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
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
                            The result is unknown. Check the original creation
                            before editing again.
                        </p>
                    )}
                    <DialogFooter>
                        <DialogClose
                            render={
                                <Button variant="outline" type="button">
                                    Cancel
                                </Button>
                            }
                        />
                        <Button type="submit" disabled={saving || name === ''}>
                            {unresolved ? 'Check creation' : 'Create'}
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
