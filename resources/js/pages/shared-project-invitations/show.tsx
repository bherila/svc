import { Head, router } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';
import { useState } from 'react';
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
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest } from '@/lib/api';
import { formatTimestampDate } from '@/lib/datetime';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { SharedInvitationProps } from '@/types/shared-projects';

/**
 * An invitation to take part in another workspace's project.
 *
 * Nothing is shared until the person chooses which of their workspaces joins
 * and accepts; declining closes it. Only workspaces they own or administer are
 * offered, since joining speaks for the whole business.
 */
export default function SharedProjectInvitation({
    invitation,
    workspaces,
    creates_light_workspace: createsLightWorkspace,
    accept_url: acceptUrl,
    decline_url: declineUrl,
    workspaces_url: workspacesUrl,
}: SharedInvitationProps) {
    const [workspaceId, setWorkspaceId] = useState<string>(
        workspaces[0]?.id ?? '',
    );
    const [clientName, setClientName] = useState('');
    const [workspaceName, setWorkspaceName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const items = workspaces.map((workspace) => ({
        value: workspace.id,
        label: workspace.name,
    }));

    const respond = async (accept: boolean) => {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);

        const result = await apiRequest<{ redirect_url: string }>(
            'POST',
            accept ? acceptUrl : declineUrl,
            accept
                ? createsLightWorkspace
                    ? {
                          client_name: clientName,
                          ...(workspaceName.trim() === ''
                              ? {}
                              : { workspace_name: workspaceName }),
                      }
                    : { workspace_id: workspaceId }
                : {},
        );

        if (!result.ok) {
            setError(
                result.errors?.workspace?.[0] ??
                    result.errors?.client_name?.[0] ??
                    result.message,
            );
            setBusy(false);

            return;
        }

        router.visit(result.data.redirect_url);
    };

    return (
        <WorkspaceShell selector>
            <Head title="Invitation to a shared project" />
            <main className={cn(SHELL_CONTAINER, 'py-8')}>
                <div className="mx-auto grid w-full max-w-xl min-w-0 grid-cols-1 gap-6">
                    <section className="grid grid-cols-1 gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Join a shared project
                        </h1>
                        <p className="wrap-anywhere text-muted-foreground">
                            {invitation.host_workspace_name} invited you to take
                            part in {invitation.project_name}.
                        </p>
                    </section>
                    <Card data-test="invitation">
                        <CardHeader>
                            <CardTitle>What joining shares</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-4">
                            <p className="text-sm text-muted-foreground">
                                The project’s tasks and comments. Time, rates,
                                invoices and payments stay in each workspace’s
                                own books, and you can leave whenever you like.
                                The invitation expires{' '}
                                {formatTimestampDate(invitation.expires_at)}.
                            </p>
                            {createsLightWorkspace ? (
                                <div className="grid grid-cols-1 gap-4">
                                    <p className="text-sm">
                                        You have no workspace of your own yet,
                                        so joining sets one up for you: a light
                                        workspace to log time, submit it for
                                        review and keep your approved
                                        timesheet. It starts empty. Nothing of{' '}
                                        {invitation.host_workspace_name}’s is
                                        copied into it.
                                    </p>
                                    <div className="grid grid-cols-1 gap-2">
                                        <Label htmlFor="join-client-name">
                                            The client you bill for this work
                                        </Label>
                                        <Input
                                            id="join-client-name"
                                            value={clientName}
                                            maxLength={120}
                                            onChange={(event) =>
                                                setClientName(event.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="grid grid-cols-1 gap-2">
                                        <Label htmlFor="join-workspace-name">
                                            Name your workspace (optional)
                                        </Label>
                                        <Input
                                            id="join-workspace-name"
                                            value={workspaceName}
                                            maxLength={120}
                                            onChange={(event) =>
                                                setWorkspaceName(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            ) : workspaces.length === 0 ? (
                                <p className="text-sm">
                                    Only an owner or admin of a workspace can
                                    accept. You are not one for any workspace
                                    other than the host’s.
                                </p>
                            ) : (
                                <div className="grid grid-cols-1 gap-2">
                                    <Label htmlFor="join-workspace">
                                        Which of your workspaces joins
                                    </Label>
                                    <Select
                                        items={items}
                                        value={workspaceId}
                                        onValueChange={(value: string | null) =>
                                            setWorkspaceId(value ?? '')
                                        }
                                    >
                                        <SelectTrigger
                                            id="join-workspace"
                                            className="w-full"
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
                                </div>
                            )}
                            {error !== null && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void respond(false)}
                                >
                                    Decline
                                </Button>
                                <Button
                                    disabled={
                                        busy ||
                                        (createsLightWorkspace
                                            ? clientName.trim() === ''
                                            : workspaceId === '')
                                    }
                                    onClick={() => void respond(true)}
                                >
                                    <CheckIcon aria-hidden />
                                    Join
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                    <p className="text-xs text-muted-foreground">
                        <a
                            className="underline underline-offset-4"
                            href={workspacesUrl}
                        >
                            Back to your workspaces
                        </a>
                    </p>
                </div>
            </main>
        </WorkspaceShell>
    );
}
