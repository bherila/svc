import { router } from '@inertiajs/react';
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

export interface PortalAccessProps {
    clientName: string;
    portal: { add_url: string; members: PortalMember[] };
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
            <AddContact addUrl={portal.add_url} projects={projects} />
        </div>
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
    projects,
}: {
    addUrl: string;
    projects: { id: string; name: string }[];
}) {
    const [email, setEmail] = useState('');
    const [scope, setScope] = useState<PortalScope>('company');
    const [chosen, setChosen] = useState<string[]>([]);
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);

    return (
        <form
            className="grid grid-cols-1 gap-3 border-t pt-4"
            onSubmit={(event) => {
                event.preventDefault();
                setBusy(true);
                router.post(
                    addUrl,
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
            }}
        >
            <div className="grid max-w-md grid-cols-1 gap-2">
                <Label htmlFor="portal-add-email">Add a contact</Label>
                <Input
                    id="portal-add-email"
                    type="email"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    placeholder="The address they sign in with"
                    required
                />
                <p className="text-xs text-muted-foreground">
                    They need to have signed in to this app once.
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
            {[errors.email, errors.projects, errors.scope]
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
            <div>
                <Button type="submit" disabled={busy}>
                    Give portal access
                </Button>
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
