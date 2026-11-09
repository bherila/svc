import { router } from '@inertiajs/react';
import { LogOutIcon, PlusIcon, UserMinusIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { apiRequest } from '@/lib/api';
import { formatTimestampDate } from '@/lib/datetime';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type {
    SharedInvitation,
    SharedParticipant,
    SharedProject,
    SharedProjectShowProps,
} from '@/types/shared-projects';

/** Confirm, then send one browser-only change and refresh what the server says is true. */
export function ConfirmedAction({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    run,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel: string;
    run: () => Promise<string | null>;
    /** Fields the decision needs, shown between the explanation and the buttons. */
    children?: ReactNode;
}) {
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle className="wrap-anywhere">
                        {title}
                    </AlertDialogTitle>
                    <AlertDialogDescription className="wrap-anywhere">
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {children}
                {error !== null && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <AlertDialogFooter>
                    <AlertDialogCancel>Keep as it is</AlertDialogCancel>
                    <AlertDialogAction
                        disabled={busy}
                        onClick={(event) => {
                            // Stay open on a refusal so it can be read.
                            event.preventDefault();
                            setBusy(true);
                            void run().then((message) => {
                                setBusy(false);
                                setError(message);

                                if (message === null) {
                                    onOpenChange(false);
                                }
                            });
                        }}
                    >
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function ParticipantRow({ participant }: { participant: SharedParticipant }) {
    const [confirming, setConfirming] = useState(false);

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1 py-3">
            <div className="min-w-0 flex-1 basis-60">
                <p className="text-sm font-medium wrap-anywhere">
                    {participant.workspace_name}
                </p>
                <p className="text-xs text-muted-foreground">
                    Joined {formatTimestampDate(participant.accepted_at)}
                </p>
            </div>
            {participant.revoke_url !== null && (
                <>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setConfirming(true)}
                    >
                        <UserMinusIcon aria-hidden />
                        Remove…
                    </Button>
                    <ConfirmedAction
                        open={confirming}
                        onOpenChange={setConfirming}
                        title={`Remove ${participant.workspace_name}?`}
                        description="It stops seeing this project at once, and its people come off the tasks. Its own time, rates and invoices stay in its workspace."
                        confirmLabel="Remove"
                        run={async () => {
                            const result = await apiRequest(
                                'DELETE',
                                participant.revoke_url ?? '',
                                {},
                            );

                            if (!result.ok) {
                                return result.message;
                            }

                            await refreshPageProps();

                            return null;
                        }}
                    />
                </>
            )}
        </li>
    );
}

function InvitationRow({ invitation }: { invitation: SharedInvitation }) {
    const [confirming, setConfirming] = useState(false);

    return (
        <li className="grid grid-cols-1 gap-1 py-3">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <p className="min-w-0 flex-1 basis-60 text-sm font-medium wrap-anywhere">
                    {invitation.email}
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setConfirming(true)}
                >
                    <UserMinusIcon aria-hidden />
                    Withdraw…
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">
                Invited · waiting to be accepted · expires{' '}
                {formatTimestampDate(invitation.expires_at)}
            </p>
            <ConfirmedAction
                open={confirming}
                onOpenChange={setConfirming}
                title="Withdraw this invitation?"
                description="The link stops working. Nothing was shared yet."
                confirmLabel="Withdraw"
                run={async () => {
                    const result = await apiRequest(
                        'DELETE',
                        invitation.revoke_url,
                        {},
                    );

                    if (!result.ok) {
                        return result.message;
                    }

                    await refreshPageProps();

                    return null;
                }}
            />
        </li>
    );
}

/** The host: who takes part, and a way to ask someone else. */
export function ParticipantsCard({
    project,
    inviteUrl,
}: {
    project: SharedProject;
    inviteUrl: string | null;
}) {
    const [email, setEmail] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [link, setLink] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const participants = project.participants ?? [];
    const invitations = project.invitations ?? [];

    const invite = async () => {
        if (inviteUrl === null || busy) {
            return;
        }

        setBusy(true);
        setError(null);

        const result = await apiRequest<{ accept_url: string }>(
            'POST',
            inviteUrl,
            { email: email.trim() },
        );

        setBusy(false);

        if (!result.ok) {
            setError(result.errors?.email?.[0] ?? result.message);

            return;
        }

        setLink(result.data.accept_url);
        setEmail('');
        void refreshPageProps();
    };

    return (
        <Card data-test="shared-participants">
            <CardHeader>
                <CardTitle>Who takes part</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4">
                {participants.length === 0 && invitations.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Only your workspace sees this project. Invite another
                        business to share its tasks and comments.
                    </p>
                )}
                {participants.length > 0 && (
                    <ul
                        aria-label="Taking part"
                        className="grid grid-cols-1 divide-y divide-border"
                    >
                        {participants.map((participant) => (
                            <ParticipantRow
                                key={participant.id}
                                participant={participant}
                            />
                        ))}
                    </ul>
                )}
                {invitations.length > 0 && (
                    <ul
                        aria-label="Invited"
                        className="grid grid-cols-1 divide-y divide-border border-t border-border"
                    >
                        {invitations.map((invitation) => (
                            <InvitationRow
                                key={invitation.id}
                                invitation={invitation}
                            />
                        ))}
                    </ul>
                )}
                {inviteUrl !== null && (
                    <form
                        noValidate
                        className="grid grid-cols-1 gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void invite();
                        }}
                    >
                        <Label htmlFor="invite-email">
                            Invite by email address
                        </Label>
                        <div className="flex flex-wrap items-start gap-2">
                            <Input
                                id="invite-email"
                                type="email"
                                value={email}
                                onChange={(event) =>
                                    setEmail(event.target.value)
                                }
                                autoComplete="off"
                                className="min-w-0 flex-1 basis-60"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={busy || email.trim() === ''}
                            >
                                <PlusIcon aria-hidden />
                                Invite
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            They accept with their own sign-in and choose which
                            of their workspaces joins. Only the project’s tasks
                            and comments are shared; time, rates and invoices
                            never are.
                        </p>
                        {error !== null && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                        {link !== null && (
                            <div className="grid grid-cols-1 gap-1">
                                <Label htmlFor="invite-link">
                                    Send them this link
                                </Label>
                                <Input
                                    id="invite-link"
                                    readOnly
                                    value={link}
                                    onFocus={(event) => event.target.select()}
                                />
                            </div>
                        )}
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

const NOT_LINKED = 'none';

/** A participant: the project of its own that carries its time, and leaving. */
export function ParticipationCard({
    project,
    urls,
    options,
}: {
    project: SharedProject;
    urls: SharedProjectShowProps['urls'];
    options: SharedProjectShowProps['local_project_options'];
}) {
    const linked = project.local_project?.id ?? NOT_LINKED;
    const [choice, setChoice] = useState<string>(linked);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const items = [
        { value: NOT_LINKED, label: 'Not linked' },
        ...options.map((option) => ({ value: option.id, label: option.name })),
    ];

    const save = async () => {
        if (urls.link === null || saving) {
            return;
        }

        setSaving(true);
        setError(null);

        const result = await apiRequest('PUT', urls.link, {
            local_project_id: choice === NOT_LINKED ? null : choice,
        });

        if (!result.ok) {
            setError(result.errors?.local_project_id?.[0] ?? result.message);
            setSaving(false);

            return;
        }

        void refreshPageProps({ onFinish: () => setSaving(false) });
    };

    return (
        <Card data-test="shared-participation">
            <CardHeader>
                <CardTitle>Your side</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4">
                <p className="text-sm text-muted-foreground">
                    {project.host_workspace_name} hosts this project. Your time,
                    rates and invoices stay in your own workspace, against your
                    own project.
                </p>
                {urls.link !== null && (
                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="local-project">Your own project</Label>
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                items={items}
                                value={choice}
                                onValueChange={(value: string | null) =>
                                    setChoice(value ?? NOT_LINKED)
                                }
                            >
                                <SelectTrigger
                                    id="local-project"
                                    className="w-64 max-w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {items.map((item) => (
                                        <SelectItem
                                            key={item.value}
                                            value={item.value}
                                        >
                                            {item.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                variant="outline"
                                onClick={() => void save()}
                                disabled={saving || choice === linked}
                            >
                                Save link
                            </Button>
                        </div>
                        {error !== null && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                    </div>
                )}
                {urls.leave !== null && (
                    <div>
                        <Button
                            variant="outline"
                            onClick={() => setLeaving(true)}
                        >
                            <LogOutIcon aria-hidden />
                            Leave this project…
                        </Button>
                        <ConfirmedAction
                            open={leaving}
                            onOpenChange={setLeaving}
                            title="Leave this project?"
                            description={`You stop seeing it and your people come off its tasks. ${project.host_workspace_name} can invite you again. Your own time and invoices are not touched.`}
                            confirmLabel="Leave"
                            run={async () => {
                                const result = await apiRequest(
                                    'POST',
                                    urls.leave ?? '',
                                    {},
                                );

                                if (!result.ok) {
                                    return result.message;
                                }

                                router.visit(urls.index);

                                return null;
                            }}
                        />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
