import { Head, Link, router } from '@inertiajs/react';
import { ChevronRightIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { AppearanceSelector } from '@/components/appearance-selector';
import { AccountMenu } from '@/components/navigation/account-menu';
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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

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

                        if (saving) {
                            return;
                        }

                        setSaving(true);
                        router.post(
                            '/workspaces',
                            { name },
                            {
                                onError: (errors) =>
                                    setError(
                                        Object.values(errors)[0] ??
                                            'That workspace could not be created.',
                                    ),
                                onFinish: () => setSaving(false),
                            },
                        );
                    }}
                >
                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="new-workspace-name">Name</Label>
                        <Input
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

                    <DialogFooter>
                        <DialogClose
                            render={
                                <Button variant="outline" type="button">
                                    Cancel
                                </Button>
                            }
                        />
                        <Button type="submit" disabled={saving || name === ''}>
                            Create
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function WorkspaceSelector({
    workspaces,
}: {
    workspaces: WorkspaceChoice[];
}) {
    const [creating, setCreating] = useState(false);

    return (
        <div
            className="min-h-screen bg-background text-foreground"
            data-appearance-bridge
        >
            <Head title="Choose a workspace" />

            <header className="border-b border-border">
                <div className="flex h-12 w-full items-center gap-2 px-4">
                    {/*
                     * Not a link. This *is* the workspace selector, so the
                     * wordmark has nowhere to take the reader from here.
                     */}
                    <span className="px-1 text-base font-bold tracking-tight">
                        SVC
                    </span>
                    <div className="ms-auto flex items-center gap-1">
                        <AppearanceSelector />
                        <AccountMenu />
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-2xl px-6 py-12">
                <div className="flex items-baseline justify-between gap-4">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Choose a workspace
                    </h1>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setCreating(true)}
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

            <NewWorkspaceDialog open={creating} onOpenChange={setCreating} />
        </div>
    );
}
