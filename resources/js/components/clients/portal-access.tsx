import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
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
import { statusLabel } from '@/lib/labels';

export type PortalScope = 'company' | 'projects';

export interface PortalMember {
    id: string;
    name: string;
    email: string;
    scope: PortalScope;
    /** Public ids of the projects a `projects` scope is limited to. */
    projects: string[];
    /** Where this person's access is changed (PUT) or removed (DELETE). */
    url: string;
}

/**
 * An invitation to an address nobody signs in with yet. Applied at that
 * person's first sign-in; `link` is the identity provider's acceptance link,
 * sent only while it can still be accepted.
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
    /** POST to send it again; null once it can no longer be sent. */
    resend_url: string | null;
    /** DELETE to withdraw it, or to dismiss one that was not applied. */
    revoke_url: string;
}

export interface PortalAccessProps {
    clientName: string;
    portal: {
        add_url: string;
        members: PortalMember[];
        /** Null while portal invitations are switched off. */
        invite_url?: string | null;
        invitations?: PortalInvitation[];
        invitation_pagination?: {
            next_url: string | null;
            previous_url: string | null;
        };
    };
    projects: { id: string; name: string }[];
}

type Errors = Record<string, string>;

/**
 * Who can use this client's portal, and how much of it each of them reaches.
 *
 * Portal access is separate from staff access: a contact reaches the whole
 * client or only the projects chosen here, and changing it never touches
 * anyone's staff role or project assignments.
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
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);
    const [revoking, setRevoking] = useState(false);
    const [copied, setCopied] = useState(false);
    const message = errors[`invitation.${invitation.id}`];
    const reach =
        invitation.scope === 'company'
            ? 'The whole client'
            : projects
                  .filter((project) => invitation.projects.includes(project.id))
                  .map((project) => project.name)
                  .join(', ');
    const notApplied = invitation.status === 'not_applied';
    const retryWithdrawal = invitation.status === 'revoked';

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
            {message !== undefined && (
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
                            setBusy(true);
                            router.post(
                                invitation.resend_url ?? '',
                                {},
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setErrors({});
                                        setCopied(false);
                                    },
                                    onError: (next) => setErrors(next),
                                    onFinish: () => setBusy(false),
                                },
                            );
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
                                setBusy(true);
                                router.delete(invitation.revoke_url, {
                                    preserveScroll: true,
                                    onError: (next) => setErrors(next),
                                    onFinish: () => {
                                        setBusy(false);
                                        setRevoking(false);
                                    },
                                });
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
    const [chosen, setChosen] = useState<string[]>(member.projects);
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);
    const [removing, setRemoving] = useState(false);
    const errorKey = `portal.${member.id}`;
    const message = errors[errorKey] ?? errors.projects ?? errors.scope;

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
            {message !== undefined && (
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
                        setBusy(true);
                        router.put(
                            member.url,
                            {
                                scope,
                                projects: scope === 'projects' ? chosen : [],
                            },
                            {
                                preserveScroll: true,
                                onSuccess: () => setErrors({}),
                                onError: (next) => setErrors(next),
                                onFinish: () => setBusy(false),
                            },
                        );
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
                                setBusy(true);
                                router.delete(member.url, {
                                    preserveScroll: true,
                                    onError: (next) => setErrors(next),
                                    onFinish: () => {
                                        setBusy(false);
                                        setRemoving(false);
                                    },
                                });
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
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);
    // Offered only after adding was refused because nobody signs in with the address.
    const offerInvitation = inviteUrl !== null && errors.invite !== undefined;
    const submit = (url: string) => {
        setBusy(true);
        router.post(
            url,
            {
                email,
                scope,
                projects: scope === 'projects' ? chosen : [],
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEmail('');
                    setScope('company');
                    setChosen([]);
                    setErrors({});
                },
                onError: (next) => setErrors(next),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <form
            className="grid grid-cols-1 gap-3 border-t pt-4"
            onSubmit={(event) => {
                event.preventDefault();
                submit(addUrl);
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
                        setErrors({});
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
            {[errors.email, errors.invite, errors.projects, errors.scope]
                .filter((message): message is string => message !== undefined)
                .map((message) => (
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
                {offerInvitation && inviteUrl !== null && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => submit(inviteUrl)}
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
