import { Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiResult, ApiSchema } from '@/lib/api';
import { statusLabel } from '@/lib/labels';

export type PortalScope = ApiSchema<'PortalContact'>['scope'];

/**
 * A portal contact as `/api/v1` presents it (#385), with same-origin
 * `update_href` (PATCH) and `revoke_href` (DELETE), and the `version` either
 * must name.
 */
export type PortalMember = ApiSchema<'PortalContact'>;

/**
 * An invitation to an address nobody signs in with yet. Applied at that
 * person's first sign-in; `link` is the identity provider's acceptance link,
 * sent only to this page and only while it can still be accepted.
 */
export interface PortalInvitation {
    id: string;
    email: string;
    scope: PortalScope;
    projects: string[];
    status: 'pending' | 'expired' | 'not_applied' | 'revoked';
    /** Why a `not_applied` invitation gave nothing. */
    reason: string | null;
    expires_at: string | null;
    email_sent: boolean;
    link: string | null;
    /** The API's resend URL (POST); null once it can no longer be sent. */
    resend_url: string | null;
    /** The API's URL (DELETE) to withdraw it, or dismiss one that was not applied. */
    revoke_url: string;
}

export interface PortalAccessProps {
    clientName: string;
    portal: {
        /** The API's grant URL (POST). */
        add_url: string;
        members: PortalMember[];
        /** The API's invitation URL (POST); null while invitations are off. */
        invite_url?: string | null;
        invitations?: PortalInvitation[];
        invitation_pagination?: {
            next_url: string | null;
            previous_url: string | null;
        };
    };
    projects: { id: string; name: string }[];
}

type Failure = Extract<ApiResult<unknown>, { ok: false }>;

/**
 * Send one change to the API, then reload the page's props so every row shows
 * what the server now holds.
 */
async function change(
    method: 'POST' | 'PATCH' | 'DELETE',
    url: string,
    body: object,
    key?: string,
): Promise<Failure | null> {
    const result = await apiRequest(method, url, body, key);

    if (!result.ok) {
        return result;
    }

    await new Promise<void>((resolve) =>
        router.reload({ onFinish: () => resolve() }),
    );

    return null;
}

/**
 * One idempotency key per attempt at an action that emails someone, kept
 * across retries of the same request, so a send whose answer was lost replays
 * rather than sending twice. A refusal or success ends the attempt.
 */
function useAttemptKey() {
    const attempt = useRef<{ key: string; body: string } | null>(null);

    return {
        keyFor(body: object): string {
            const signature = JSON.stringify(body);

            if (attempt.current?.body !== signature) {
                attempt.current = { key: newIdempotencyKey(), body: signature };
            }

            return attempt.current.key;
        },
        settle(failure: Failure | null) {
            if (
                failure === null ||
                (failure.status >= 400 && failure.status < 500)
            ) {
                attempt.current = null;
            }
        },
    };
}

/**
 * Who can use this client's portal, and how much of it each of them reaches.
 *
 * Portal access is separate from staff access: a contact reaches the whole
 * client or only the projects chosen here, and changing it never touches
 * anyone's staff role or project assignments. Every change goes to the same
 * `/api/v1` operations agents use.
 */
export function PortalAccess({
    clientName,
    portal,
    projects,
}: PortalAccessProps) {
    return (
        <div className="grid grid-cols-1 gap-4">
            <p className="text-sm text-muted-foreground">
                People who can sign in to this client&apos;s portal. Staff
                access is managed separately.
            </p>
            {portal.members.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nobody can use this client&apos;s portal yet.
                </p>
            ) : (
                <ul className="grid grid-cols-1 gap-3">
                    {portal.members.map((member) => (
                        <MemberRow
                            key={member.id}
                            clientName={clientName}
                            member={member}
                            projects={projects}
                        />
                    ))}
                </ul>
            )}
            {(portal.invitations ?? []).length > 0 && (
                <div className="grid grid-cols-1 gap-3">
                    <h3 className="text-sm font-medium">Invitations</h3>
                    <ul className="grid grid-cols-1 gap-3">
                        {(portal.invitations ?? []).map((invitation) => (
                            <InvitationRow
                                key={invitation.id}
                                invitation={invitation}
                                projects={projects}
                            />
                        ))}
                    </ul>
                </div>
            )}
            {(portal.invitation_pagination?.previous_url ||
                portal.invitation_pagination?.next_url) && (
                <nav
                    aria-label="Invitation pages"
                    className="flex flex-wrap gap-4 text-sm"
                >
                    {portal.invitation_pagination.previous_url && (
                        <Link
                            href={portal.invitation_pagination.previous_url}
                            preserveScroll
                            className="underline"
                        >
                            Newer invitations
                        </Link>
                    )}
                    {portal.invitation_pagination.next_url && (
                        <Link
                            href={portal.invitation_pagination.next_url}
                            preserveScroll
                            className="underline"
                        >
                            Older invitations
                        </Link>
                    )}
                </nav>
            )}
            <AddContact
                addUrl={portal.add_url}
                inviteUrl={portal.invite_url ?? null}
                projects={projects}
            />
        </div>
    );
}

function InvitationRow({
    invitation,
    projects,
}: {
    invitation: PortalInvitation;
    projects: { id: string; name: string }[];
}) {
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [revoking, setRevoking] = useState(false);
    const [copied, setCopied] = useState(false);
    const resendKey = useAttemptKey();
    const revokeKey = useAttemptKey();
    const reach =
        invitation.scope === 'company'
            ? 'The whole client'
            : projects
                  .filter((project) => invitation.projects.includes(project.id))
                  .map((project) => project.name)
                  .join(', ');
    const notApplied = invitation.status === 'not_applied';
    const retryWithdrawal = invitation.status === 'revoked';

    const resend = async (url: string) => {
        const body = { confirm: true };
        setBusy(true);
        const failure = await change('POST', url, body, resendKey.keyFor(body));
        resendKey.settle(failure);
        setMessage(failure?.message ?? null);

        if (failure === null) {
            setCopied(false);
        }

        setBusy(false);
    };

    const revoke = async () => {
        const body = { confirm: true };
        setBusy(true);
        const failure = await change(
            'DELETE',
            invitation.revoke_url,
            body,
            revokeKey.keyFor(body),
        );
        revokeKey.settle(failure);
        setMessage(failure?.message ?? null);
        setBusy(false);
        setRevoking(false);
    };

    return (
        <li className="grid grid-cols-1 gap-2 rounded-lg border p-3">
            <div className="grid grid-cols-1 gap-0.5 text-sm">
                <span className="font-medium wrap-anywhere">
                    {invitation.email}
                </span>
                <span className="wrap-anywhere text-muted-foreground">
                    {reach}
                </span>
                <span className="text-muted-foreground">
                    {statusLabel(invitation.status)}
                    {invitation.status === 'pending' &&
                        invitation.expires_at !== null &&
                        ` until ${new Date(invitation.expires_at).toLocaleDateString()}`}
                    {invitation.status === 'pending' &&
                        !invitation.email_sent &&
                        '. The identity provider could not email it; send the link yourself.'}
                </span>
                {invitation.reason !== null && (
                    <span className="wrap-anywhere text-muted-foreground">
                        {invitation.reason}
                    </span>
                )}
            </div>
            {invitation.link !== null && (
                <div className="grid grid-cols-1 gap-1">
                    <Label htmlFor={`invitation-link-${invitation.id}`}>
                        Invitation link
                    </Label>
                    <div className="flex min-w-0 gap-2">
                        <Input
                            id={`invitation-link-${invitation.id}`}
                            readOnly
                            value={invitation.link}
                            className="min-w-0 flex-1"
                            onFocus={(event) => event.target.select()}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                void navigator.clipboard
                                    ?.writeText(invitation.link ?? '')
                                    .then(() => setCopied(true));
                            }}
                        >
                            {copied ? 'Copied' : 'Copy link'}
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Only the invited address can accept it. Share it only
                        with them.
                    </p>
                </div>
            )}
            {message !== null && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {message}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                {invitation.resend_url !== null && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => {
                            void resend(invitation.resend_url ?? '');
                        }}
                    >
                        Send again
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    disabled={busy}
                    onClick={() => setRevoking(true)}
                >
                    {notApplied
                        ? 'Dismiss'
                        : retryWithdrawal
                          ? 'Retry withdrawal'
                          : 'Withdraw'}
                </Button>
            </div>
            <AlertDialog
                open={revoking}
                onOpenChange={(open) => {
                    if (!open && !busy) {
                        setRevoking(false);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {notApplied
                                ? 'Dismiss this invitation?'
                                : 'Withdraw this invitation?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription className="wrap-anywhere">
                            {notApplied
                                ? `It gave ${invitation.email} nothing, and will no longer be listed.`
                                : `The link stops working, and ${invitation.email} gets no portal access when they sign in.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={(event) => {
                                event.preventDefault();
                                void revoke();
                            }}
                        >
                            {notApplied ? 'Dismiss' : 'Withdraw invitation'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </li>
    );
}

function MemberRow({
    clientName,
    member,
    projects,
}: {
    clientName: string;
    member: PortalMember;
    projects: { id: string; name: string }[];
}) {
    const [scope, setScope] = useState<PortalScope>(member.scope);
    const [chosen, setChosen] = useState<string[]>(
        member.projects.map((project) => project.id),
    );
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [removing, setRemoving] = useState(false);

    const save = async () => {
        setBusy(true);
        const failure = await change('PATCH', member.update_href, {
            scope,
            projects: scope === 'projects' ? chosen : [],
            expected_version: member.version,
            confirm: true,
        });
        setMessage(failure?.message ?? null);
        setBusy(false);
    };

    const remove = async () => {
        setBusy(true);
        const failure = await change('DELETE', member.revoke_href, {
            expected_version: member.version,
            confirm: true,
        });
        // A removed contact's row is gone once the page reloads.
        setMessage(failure?.message ?? null);
        setBusy(false);
        setRemoving(false);
    };

    return (
        <li className="grid grid-cols-1 gap-2 rounded-lg border p-3">
            <div className="grid grid-cols-1 gap-0.5 text-sm">
                <span className="font-medium wrap-anywhere">{member.name}</span>
                <span className="wrap-anywhere text-muted-foreground">
                    {member.email}
                </span>
            </div>
            <ScopeFields
                idPrefix={`portal-${member.id}`}
                label={`Portal access for ${member.name}`}
                scope={scope}
                chosen={chosen}
                projects={projects}
                onScope={setScope}
                onChosen={setChosen}
            />
            {message !== null && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {message}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={busy}
                    onClick={() => {
                        void save();
                    }}
                >
                    Save access
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    disabled={busy}
                    onClick={() => setRemoving(true)}
                >
                    Remove
                </Button>
            </div>
            <AlertDialog
                open={removing}
                onOpenChange={(open) => {
                    if (!open && !busy) {
                        setRemoving(false);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Remove portal access?
                        </AlertDialogTitle>
                        <AlertDialogDescription className="wrap-anywhere">
                            {member.name} will no longer be able to use the{' '}
                            {clientName} portal. Their account stays, and you
                            can add them again later.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={(event) => {
                                event.preventDefault();
                                void remove();
                            }}
                        >
                            Remove access
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </li>
    );
}

function AddContact({
    addUrl,
    inviteUrl,
    projects,
}: {
    addUrl: string;
    inviteUrl: string | null;
    projects: { id: string; name: string }[];
}) {
    const [email, setEmail] = useState('');
    const [scope, setScope] = useState<PortalScope>('company');
    const [chosen, setChosen] = useState<string[]>([]);
    const [messages, setMessages] = useState<string[]>([]);
    // Set only when adding was refused because nobody signs in with the address.
    const [inviteOffered, setInviteOffered] = useState(false);
    const [busy, setBusy] = useState(false);
    const inviteKey = useAttemptKey();
    const offerInvitation = inviteUrl !== null && inviteOffered;

    const submit = async (invite: boolean) => {
        const body = {
            email,
            scope,
            projects: scope === 'projects' ? chosen : [],
            confirm: true,
        };
        setBusy(true);
        const failure =
            invite && inviteUrl !== null
                ? await change('POST', inviteUrl, body, inviteKey.keyFor(body))
                : await change('POST', addUrl, body);

        if (invite) {
            inviteKey.settle(failure);
        }

        if (failure === null) {
            setEmail('');
            setScope('company');
            setChosen([]);
            setMessages([]);
            setInviteOffered(false);
        } else {
            const fields = Object.values(failure.errors ?? {}).flat();
            setMessages(fields.length > 0 ? fields : [failure.message]);
            setInviteOffered(
                !invite && (failure.errors?.invite ?? []).length > 0,
            );
        }

        setBusy(false);
    };

    return (
        <form
            className="grid grid-cols-1 gap-3 border-t pt-4"
            onSubmit={(event) => {
                event.preventDefault();
                void submit(false);
            }}
        >
            <div className="grid max-w-md grid-cols-1 gap-2">
                <Label htmlFor="portal-add-email">Add a contact</Label>
                <Input
                    id="portal-add-email"
                    type="email"
                    value={email}
                    onChange={(event) => {
                        setEmail(event.target.value);
                        setMessages([]);
                        setInviteOffered(false);
                    }}
                    placeholder="The address they sign in with"
                    required
                />
                <p className="text-xs text-muted-foreground">
                    {inviteUrl === null
                        ? 'They need to have signed in to this app once.'
                        : 'Someone who has never signed in can be sent an invitation instead.'}
                </p>
            </div>
            <ScopeFields
                idPrefix="portal-add"
                label="Portal access for the new contact"
                scope={scope}
                chosen={chosen}
                projects={projects}
                onScope={setScope}
                onChosen={setChosen}
            />
            {messages.map((message) => (
                <p
                    key={message}
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {message}
                </p>
            ))}
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={busy}>
                    Give portal access
                </Button>
                {offerInvitation && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => {
                            void submit(true);
                        }}
                    >
                        Send invitation
                    </Button>
                )}
            </div>
        </form>
    );
}

/**
 * The whole client, or only some of its projects. "No access" is not here:
 * that is removing the person, which asks to be confirmed.
 */
function ScopeFields({
    idPrefix,
    label,
    scope,
    chosen,
    projects,
    onScope,
    onChosen,
}: {
    idPrefix: string;
    label: string;
    scope: PortalScope;
    chosen: string[];
    projects: { id: string; name: string }[];
    onScope: (scope: PortalScope) => void;
    onChosen: (chosen: string[]) => void;
}) {
    return (
        <div className="grid grid-cols-1 gap-2 text-sm">
            <select
                aria-label={label}
                className="h-9 max-w-xs rounded-md border bg-transparent px-2"
                value={scope}
                onChange={(event) => onScope(event.target.value as PortalScope)}
            >
                <option value="company">The whole client</option>
                <option value="projects" disabled={projects.length === 0}>
                    Selected projects
                </option>
            </select>
            {scope === 'projects' && (
                <fieldset className="grid grid-cols-1 gap-1">
                    <legend className="sr-only">Projects</legend>
                    {projects.map((project) => {
                        const id = `${idPrefix}-${project.id}`;

                        return (
                            <label
                                key={project.id}
                                htmlFor={id}
                                className="flex items-start gap-2"
                            >
                                <input
                                    id={id}
                                    type="checkbox"
                                    className="mt-1"
                                    checked={chosen.includes(project.id)}
                                    onChange={(event) =>
                                        onChosen(
                                            event.target.checked
                                                ? [...chosen, project.id]
                                                : chosen.filter(
                                                      (other) =>
                                                          other !== project.id,
                                                  ),
                                        )
                                    }
                                />
                                <span className="min-w-0 wrap-anywhere">
                                    {project.name}
                                </span>
                            </label>
                        );
                    })}
                </fieldset>
            )}
        </div>
    );
}
