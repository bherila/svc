import { PlusIcon, UserMinusIcon } from 'lucide-react';
import { useState } from 'react';
import { ConfirmedAction } from '@/components/shared-projects/participation-panels';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import type { ReviewerGrantRow, ReviewerGrantsProps } from '@/types/review';

const WHOLE_CLIENT = 'all';

type Option = { value: string; label: string };

function Chooser({
    id,
    label,
    items,
    value,
    onChange,
}: {
    id: string;
    label: string;
    items: Option[];
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid grid-cols-1 gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Select
                items={items}
                value={value}
                onValueChange={(next: string | null) => onChange(next ?? '')}
            >
                <SelectTrigger id={id} className="w-full min-w-0">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {items.map((item) => (
                        <SelectItem key={item.value} value={item.value}>
                            {item.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

/**
 * Give review access: one client (or one of its projects), and one person or
 * workspace. The server decides who may be named and says why when it refuses;
 * nothing here computes a permission.
 */
export function GrantForm({
    storeUrl,
    clients,
    workspaces,
}: Pick<ReviewerGrantsProps, 'clients' | 'workspaces'> & {
    storeUrl: string;
}) {
    const [clientId, setClientId] = useState(clients[0]?.id ?? '');
    const [projectId, setProjectId] = useState(WHOLE_CLIENT);
    const [kind, setKind] = useState<'contact' | 'workspace'>('contact');
    const [contactId, setContactId] = useState('');
    const [workspaceId, setWorkspaceId] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const client = clients.find((candidate) => candidate.id === clientId);

    const clientItems = clients.map((c) => ({ value: c.id, label: c.name }));
    const projectItems: Option[] = [
        { value: WHOLE_CLIENT, label: 'All of this client’s projects' },
        ...(client?.projects ?? []).map((p) => ({
            value: p.id,
            label: p.name,
        })),
    ];
    const kindItems: Option[] = [
        { value: 'contact', label: 'A contact of this client' },
        { value: 'workspace', label: 'A workspace you share a project with' },
    ];
    const contactItems: Option[] = (client?.contacts ?? []).map((c) => ({
        value: c.id,
        label: c.name === '' ? c.email : `${c.name} · ${c.email}`,
    }));
    const workspaceItems: Option[] = workspaces.map((w) => ({
        value: w.id,
        label: w.name,
    }));
    const target = kind === 'contact' ? contactId : workspaceId;

    const give = async () => {
        if (busy || clientId === '' || target === '') {
            return;
        }

        setBusy(true);
        setError(null);

        const result = await apiRequest('POST', storeUrl, {
            client_id: clientId,
            project_id: projectId === WHOLE_CLIENT ? null : projectId,
            kind,
            contact_id: kind === 'contact' ? contactId : null,
            workspace_id: kind === 'workspace' ? workspaceId : null,
        });

        setBusy(false);

        if (!result.ok) {
            setError(
                result.errors?.contact_id?.[0] ??
                    result.errors?.workspace_id?.[0] ??
                    result.errors?.project_id?.[0] ??
                    result.message,
            );

            return;
        }

        setContactId('');
        setWorkspaceId('');
        await refreshPageProps();
    };

    return (
        <Card data-test="grant-form">
            <CardHeader>
                <CardTitle>Give review access</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4">
                {clients.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Add a client first. Review access is always for one of
                        your clients.
                    </p>
                ) : (
                    <form
                        noValidate
                        className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] items-end gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void give();
                        }}
                    >
                        <Chooser
                            id="grant-client"
                            label="Client"
                            items={clientItems}
                            value={clientId}
                            onChange={(next) => {
                                setClientId(next);
                                setProjectId(WHOLE_CLIENT);
                                setContactId('');
                            }}
                        />
                        <Chooser
                            id="grant-project"
                            label="Projects"
                            items={projectItems}
                            value={projectId}
                            onChange={setProjectId}
                        />
                        <Chooser
                            id="grant-kind"
                            label="Who reviews"
                            items={kindItems}
                            value={kind}
                            onChange={(next) =>
                                setKind(
                                    next === 'workspace'
                                        ? 'workspace'
                                        : 'contact',
                                )
                            }
                        />
                        {kind === 'contact' ? (
                            contactItems.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    This client has no contact with an email
                                    address. Add one on the client first.
                                </p>
                            ) : (
                                <Chooser
                                    id="grant-contact"
                                    label="Contact"
                                    items={contactItems}
                                    value={contactId}
                                    onChange={setContactId}
                                />
                            )
                        ) : workspaceItems.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No workspace shares a project with you yet.
                                Share a project first, then name its workspace
                                here.
                            </p>
                        ) : (
                            <Chooser
                                id="grant-workspace"
                                label="Workspace"
                                items={workspaceItems}
                                value={workspaceId}
                                onChange={setWorkspaceId}
                            />
                        )}
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            <Button
                                type="submit"
                                variant="outline"
                                className="min-h-11 md:min-h-8"
                                disabled={busy || target === ''}
                            >
                                <PlusIcon aria-hidden />
                                Give access
                            </Button>
                        </div>
                    </form>
                )}
                <p className="text-xs text-muted-foreground">
                    They see this client’s hours and expenses that are ready to
                    bill, and approve or dispute them. They never see rates,
                    invoices or internal notes. You can take access back at any
                    time.
                </p>
                {error !== null && (
                    <p
                        role="alert"
                        className="text-sm wrap-anywhere text-destructive"
                    >
                        {error}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function GrantItem({ grant }: { grant: ReviewerGrantRow }) {
    const [confirming, setConfirming] = useState(false);
    const scope =
        grant.project === null
            ? grant.client
            : `${grant.client} · ${grant.project}`;

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1 py-3">
            <div className="min-w-0 flex-1 basis-60">
                <p className="text-sm font-medium wrap-anywhere">
                    {grant.grantee}
                </p>
                <p className="text-xs wrap-anywhere text-muted-foreground">
                    {grant.kind === 'workspace' ? 'Workspace' : 'Contact'}
                    {grant.detail !== null && ` · ${grant.detail}`} · {scope}
                </p>
                <p className="text-xs text-muted-foreground">
                    {grant.revoked_at === null
                        ? `Given ${formatTimestampDate(grant.granted_at)}`
                        : `Revoked ${formatTimestampDate(grant.revoked_at)}`}
                </p>
            </div>
            {grant.revoke_url !== null && (
                <>
                    <Button
                        variant="outline"
                        size="sm"
                        className="min-h-11 md:min-h-7"
                        onClick={() => setConfirming(true)}
                    >
                        <UserMinusIcon aria-hidden />
                        Take back…
                    </Button>
                    <ConfirmedAction
                        open={confirming}
                        onOpenChange={setConfirming}
                        title={`Take back access from ${grant.grantee}?`}
                        description="They stop seeing this client’s hours and expenses at once. Anything they already approved stays approved."
                        confirmLabel="Take back"
                        run={async () => {
                            const result = await apiRequest(
                                'DELETE',
                                grant.revoke_url ?? '',
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

/** Who has review access now, and who had it. */
export function GrantList({ grants }: { grants: ReviewerGrantRow[] }) {
    const active = grants.filter((grant) => grant.revoked_at === null);
    const past = grants.filter((grant) => grant.revoked_at !== null);

    return (
        <Card data-test="grant-list">
            <CardHeader>
                <CardTitle>Who can review</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4">
                {active.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nobody outside your team can see or approve your hours
                        and expenses.
                    </p>
                ) : (
                    <ul
                        aria-label="With access"
                        className="grid grid-cols-1 divide-y divide-border"
                    >
                        {active.map((grant) => (
                            <GrantItem key={grant.id} grant={grant} />
                        ))}
                    </ul>
                )}
                {past.length > 0 && (
                    <details>
                        <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm underline underline-offset-4 md:min-h-8">
                            Taken back ({past.length})
                        </summary>
                        <ul
                            aria-label="Taken back"
                            className="grid grid-cols-1 divide-y divide-border"
                        >
                            {past.map((grant) => (
                                <GrantItem key={grant.id} grant={grant} />
                            ))}
                        </ul>
                    </details>
                )}
            </CardContent>
        </Card>
    );
}
