import { Head, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { PaymentReminderCadenceCard } from '@/components/billing/payment-reminders';
import type { CompanyRemindersState } from '@/components/billing/payment-reminders';
import { ClientContacts } from '@/components/clients/client-contacts';
import type { ClientContact } from '@/components/clients/client-contacts';
import { CompanyProfile } from '@/components/clients/company-profile';
import type { CompanyProfileData } from '@/components/clients/company-profile';
import { PortalAccess } from '@/components/clients/portal-access';
import type { PortalAccessProps } from '@/components/clients/portal-access';
import { useClientChange } from '@/components/clients/use-client-change';
import { useClientDraft } from '@/components/clients/use-client-draft';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useUnknownWriteGuard } from '@/hooks/use-unknown-write-guard';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { cn } from '@/lib/utils';

type ManagedCompany = {
    id: string;
    version: string;
    update_url: string;
    name: string;
    billing_email: string | null;
    automatic_invoice_email_enabled?: boolean;
    automatic_invoice_email_delay_days?: number | null;
    invoice_recipients?: { email: string; label: string }[];
    is_active: boolean;
    /** The confirmed Square customer (#438), or null when none is. */
    square_customer?: ApiSchema<'CompanySquareCustomer'> | null;
};

type ProjectMember = { user: string; role: string };

type ManagedProject = {
    id: string;
    name: string;
    description: string | null;
    /** Canonical `host/owner/name`, or null when nobody has mapped it. */
    repository: string | null;
    status: string;
    is_visible_to_client: boolean;
    version: string;
    update_url: string;
    members_update_url: string;
    members: ProjectMember[];
};

type ProjectCreate = {
    url: string;
    company_id: string;
    expected_version: string;
};

type AssignableMember = { id: string; name: string };

/**
 * Roles a project membership can hold, plus removal.
 *
 * "No access" is an option in the same list rather than a separate delete
 * control, because it is the same decision: who may reach this project. A
 * separate button invites the half-finished state where someone is granted
 * before anyone considers what they are replacing.
 */
const PROJECT_ROLES = [
    { value: 'owner', label: 'Owner' },
    { value: 'manager', label: 'Manager' },
    { value: 'contributor', label: 'Contributor' },
    { value: 'viewer', label: 'Viewer' },
    { value: 'none', label: 'No access' },
] as const;

/** Keep a retry on its original receipt until the server confirms the write. */
function useProjectWrite() {
    const attempt = useRef<{
        method: 'POST' | 'PATCH' | 'PUT';
        url: string;
        body: object;
        key: string;
        uncertain: boolean;
        saved?: ApiSchema<'ProjectResponse'>['data'];
    } | null>(null);
    const running = useRef(false);
    const [busy, setBusy] = useState(false);
    const [unresolved, setUnresolved] = useState(false);
    const [confirmed, setConfirmed] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    useUnknownWriteGuard(
        () => attempt.current !== null && attempt.current.saved === undefined,
        () =>
            setFailure(
                'Check the original project result before leaving this page.',
            ),
    );

    async function write(
        method: 'POST' | 'PATCH' | 'PUT',
        url: string,
        body: object,
    ) {
        if (running.current) {
            return null;
        }

        running.current = true;
        setBusy(true);
        setFailure(null);
        attempt.current ??= {
            method,
            url,
            body: structuredClone(body),
            key: newIdempotencyKey(),
            uncertain: false,
        };
        const original = attempt.current;

        try {
            if (original.saved === undefined) {
                const result = await apiRequest<
                    ApiSchema<'ProjectResponse'>['data']
                >(original.method, original.url, original.body, original.key);

                if (!result.ok) {
                    original.uncertain ||=
                        result.status === 0 ||
                        result.status === 408 ||
                        result.status >= 500;
                    setUnresolved(original.uncertain);
                    setFailure(
                        Object.values(result.errors ?? {})
                            .flat()
                            .join(' ') || result.message,
                    );

                    if (!original.uncertain) {
                        attempt.current = null;
                    }

                    return null;
                }

                if (
                    !result.data ||
                    typeof result.data.version !== 'string' ||
                    typeof result.data.name !== 'string'
                ) {
                    original.uncertain = true;
                    setUnresolved(true);
                    setFailure(
                        'SVC’s response could not be read. Check the original change.',
                    );

                    return null;
                }

                original.saved = result.data;
                setConfirmed(true);
            }

            setUnresolved(true);
            await refreshPageProps({
                only: ['projects', 'project_create'],
                requireSuccess: true,
            });
            const saved = original.saved;
            attempt.current = null;
            setUnresolved(false);
            setConfirmed(false);

            return saved;
        } catch {
            original.uncertain = true;
            setUnresolved(true);
            setFailure(
                original.saved
                    ? 'The project change was saved, but its page could not refresh. Refresh the saved result before editing again.'
                    : 'The original project result could not be checked. Check the original change before editing again.',
            );

            return null;
        } finally {
            running.current = false;
            setBusy(false);
        }
    }

    return { busy, unresolved, confirmed, failure, write };
}

function ProjectAccess({
    project,
    assignable,
}: {
    project: ManagedProject;
    assignable: AssignableMember[];
}) {
    if (assignable.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Every member of this workspace is an owner or admin, so they
                already reach this project.
            </p>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-2">
            {assignable.map((member) => (
                <AccessRow
                    key={member.id}
                    project={project}
                    member={member}
                    role={
                        project.members.find((row) => row.user === member.id)
                            ?.role ?? 'none'
                    }
                />
            ))}
        </div>
    );
}

function AccessRow({
    project,
    member,
    role,
}: {
    project: ManagedProject;
    member: AssignableMember;
    role: string;
}) {
    const mutation = useProjectWrite();

    return (
        <div className="grid grid-cols-1 gap-1">
            <div className="flex min-w-0 flex-wrap items-center gap-3 text-sm">
                <span className="min-w-0 wrap-anywhere">{member.name}</span>
                <select
                    aria-label={`Access for ${member.name}`}
                    className="h-9 rounded-md border bg-transparent px-2"
                    disabled={mutation.busy || mutation.unresolved}
                    value={role}
                    onChange={(event) =>
                        void mutation.write('PUT', project.members_update_url, {
                            user_id: member.id,
                            role: event.target.value,
                            expected_version: project.version,
                            confirm: true,
                        })
                    }
                >
                    {PROJECT_ROLES.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>
            {mutation.failure && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {mutation.failure}
                </p>
            )}
            {mutation.unresolved && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={mutation.busy}
                    onClick={() =>
                        void mutation.write(
                            'PUT',
                            project.members_update_url,
                            {},
                        )
                    }
                >
                    Check access change
                </Button>
            )}
        </div>
    );
}

/**
 * The shape of the client record, edited from the client's own tab.
 *
 * Client settings is reached from the client, not from a parallel admin
 * section, so an operator never
 * has to leave the client they are working in to change it - and there is no
 * second copy of these fields elsewhere to drift out of step.
 *
 * The agreement is deliberately not here. It lives on Overview with the rest of
 * what the engagement *is*; this tab is the record around it - who the client
 * is, and what projects exist.
 */
function CompanyForm({ company }: { company: ManagedCompany }) {
    const form = useClientDraft(
        {
            name: company.name,
            billing_email: company.billing_email ?? '',
            automatic_invoice_email_enabled:
                company.automatic_invoice_email_enabled ?? false,
            automatic_invoice_email_delay_days:
                company.automatic_invoice_email_delay_days ?? 0,
            is_active: company.is_active,
        },
        company.version,
    );

    const change = useClientChange();

    return (
        <form
            className="grid grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                const submitted = { ...form.data };
                void change.run(
                    'PATCH',
                    company.update_url,
                    {
                        ...submitted,
                        billing_email:
                            submitted.billing_email === ''
                                ? null
                                : submitted.billing_email,
                        expected_version: form.version,
                    },
                    async (result) => {
                        form.saved(submitted, result.data.version);
                        await refreshPageProps({
                            only: [
                                'company',
                                'company_profile',
                                'project_create',
                                'workspaceNavigation',
                            ],
                            requireSuccess: true,
                        });
                    },
                );
            }}
        >
            {/*
             * Sized to what goes in them. A name and an email stretched the
             * full width of the page, which reads as a form nobody finished
             * laying out - and makes the eye travel the whole line to check a
             * value twenty characters long.
             */}
            <div className="grid max-w-md grid-cols-1 gap-2">
                <Label htmlFor="company-name">Name</Label>
                <Input
                    disabled={change.locked}
                    id="company-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                {change.errors.name !== undefined && (
                    <p className="text-sm text-destructive">
                        {change.errors.name?.[0]}
                    </p>
                )}
            </div>

            <div className="grid max-w-md grid-cols-1 gap-2">
                <Label htmlFor="company-billing-email">Billing email</Label>
                <Input
                    disabled={change.locked}
                    id="company-billing-email"
                    type="email"
                    value={form.data.billing_email}
                    onChange={(event) =>
                        form.setData('billing_email', event.target.value)
                    }
                    placeholder="Not set"
                />
                {change.errors.billing_email !== undefined && (
                    <p className="text-sm text-destructive">
                        {change.errors.billing_email?.[0]}
                    </p>
                )}
            </div>

            <div className="flex items-center gap-3">
                <Switch
                    disabled={change.locked}
                    id="company-active"
                    checked={form.data.is_active}
                    onCheckedChange={(next) =>
                        form.setData('is_active', next === true)
                    }
                />
                <Label htmlFor="company-active">Active client</Label>
            </div>

            <div className="grid max-w-xl grid-cols-1 gap-3 rounded-lg border p-4">
                <div className="flex items-center gap-3">
                    <Switch
                        disabled={change.locked}
                        id="automatic-invoice-email"
                        checked={form.data.automatic_invoice_email_enabled}
                        onCheckedChange={(next) =>
                            form.setData(
                                'automatic_invoice_email_enabled',
                                next === true,
                            )
                        }
                    />
                    <Label htmlFor="automatic-invoice-email">
                        Automatically email issued invoices to clients
                    </Label>
                </div>

                {form.data.automatic_invoice_email_enabled && (
                    <div className="grid max-w-xs grid-cols-1 gap-2">
                        <Label htmlFor="automatic-invoice-delay">
                            Days after issue
                        </Label>
                        <Input
                            disabled={change.locked}
                            id="automatic-invoice-delay"
                            type="number"
                            min={0}
                            max={365}
                            step={1}
                            value={form.data.automatic_invoice_email_delay_days}
                            onChange={(event) =>
                                form.setData(
                                    'automatic_invoice_email_delay_days',
                                    Number.parseInt(
                                        event.target.value || '0',
                                        10,
                                    ),
                                )
                            }
                        />
                        <p className="text-xs wrap-anywhere text-muted-foreground">
                            Zero makes a newly issued invoice eligible
                            immediately. The maximum review window is 365
                            calendar days in the workspace timezone.
                        </p>
                    </div>
                )}

                <div className="text-sm">
                    <p className="font-medium">Configured recipients</p>
                    {(company.invoice_recipients ?? []).length === 0 ? (
                        <p className="wrap-anywhere text-destructive">
                            None. Add a billing email or a client portal user
                            before enabling automatic delivery.
                        </p>
                    ) : (
                        <ul className="grid grid-cols-1 gap-1 text-muted-foreground">
                            {(company.invoice_recipients ?? []).map(
                                (recipient) => (
                                    <li
                                        key={recipient.email}
                                        className="wrap-anywhere"
                                    >
                                        {recipient.label}: {recipient.email}
                                    </li>
                                ),
                            )}
                        </ul>
                    )}
                </div>

                {change.errors.automatic_invoice_email_enabled !==
                    undefined && (
                    <p className="text-sm wrap-anywhere text-destructive">
                        {change.errors.automatic_invoice_email_enabled?.[0]}
                    </p>
                )}
                {change.errors.automatic_invoice_email_delay_days !==
                    undefined && (
                    <p className="text-sm wrap-anywhere text-destructive">
                        {change.errors.automatic_invoice_email_delay_days?.[0]}
                    </p>
                )}
            </div>

            <div>
                {change.error && (
                    <p role="alert" className="text-sm text-destructive">
                        {change.error}
                    </p>
                )}
                <Button type="submit" disabled={change.locked}>
                    Save client
                </Button>
                {change.unresolved && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={change.busy}
                        onClick={() => void change.check()}
                    >
                        Check original change
                    </Button>
                )}
            </div>
        </form>
    );
}

function ProjectForm({
    project,
    assignable,
}: {
    project: ManagedProject;
    assignable: AssignableMember[];
}) {
    const form = useForm({
        expected_version: project.version,
        name: project.name,
        description: project.description ?? '',
        repository: project.repository ?? '',
        status: project.status,
        is_visible_to_client: project.is_visible_to_client,
    });

    const mutation = useProjectWrite();

    return (
        <form
            className="grid grid-cols-1 gap-3 rounded-xl border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                void mutation
                    .write('PATCH', project.update_url, {
                        ...form.data,
                        description: form.data.description || null,
                        repository: form.data.repository || null,
                        confirm: true,
                    })
                    .then((saved) => {
                        if (saved) {
                            form.setData({
                                expected_version: saved.version,
                                name: saved.name,
                                description: saved.description ?? '',
                                repository: saved.repository ?? '',
                                status: saved.status,
                                is_visible_to_client:
                                    saved.is_visible_to_client,
                            });
                        }
                    });
            }}
        >
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    disabled={mutation.busy || mutation.unresolved}
                    aria-label={`Name of ${project.name}`}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    className="max-w-xs"
                />
                <Badge variant="outline">{statusLabel(project.status)}</Badge>
            </div>

            <Textarea
                disabled={mutation.busy || mutation.unresolved}
                aria-label={`Description of ${project.name}`}
                value={form.data.description}
                onChange={(event) =>
                    form.setData('description', event.target.value)
                }
                placeholder="No description"
                rows={2}
            />

            {/*
                The repository this project's work happens in. Typed once here
                so an agent in that checkout can resolve it without asking, and
                stored canonically - paste whatever `git remote get-url origin`
                printed and the server reduces it to one spelling.
            */}
            <div className="grid grid-cols-1 gap-1">
                <Label htmlFor={`repository-${project.id}`}>Repository</Label>
                <Input
                    disabled={mutation.busy || mutation.unresolved}
                    id={`repository-${project.id}`}
                    value={form.data.repository}
                    onChange={(event) =>
                        form.setData('repository', event.target.value)
                    }
                    placeholder="github.com/owner/name"
                />
                {form.errors.repository ? (
                    <p role="alert" className="text-sm text-destructive">
                        {form.errors.repository}
                    </p>
                ) : null}
            </div>

            {mutation.failure && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {mutation.failure}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-6">
                <div className="flex items-center gap-3">
                    <Switch
                        disabled={mutation.busy || mutation.unresolved}
                        id={`visible-${project.id}`}
                        checked={form.data.is_visible_to_client}
                        onCheckedChange={(next) =>
                            form.setData('is_visible_to_client', next === true)
                        }
                    />
                    <Label htmlFor={`visible-${project.id}`}>
                        Client can see this project
                    </Label>
                </div>
                <div className="flex items-center gap-3">
                    <Switch
                        disabled={mutation.busy || mutation.unresolved}
                        id={`archived-${project.id}`}
                        checked={form.data.status === 'archived'}
                        onCheckedChange={(next) =>
                            form.setData(
                                'status',
                                next === true ? 'archived' : 'active',
                            )
                        }
                    />
                    <Label htmlFor={`archived-${project.id}`}>Archived</Label>
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    disabled={mutation.busy}
                >
                    {mutation.confirmed
                        ? 'Refresh saved project'
                        : mutation.unresolved
                          ? 'Check project save'
                          : 'Save project'}
                </Button>
            </div>

            {mutation.unresolved && (
                <p
                    role="status"
                    className="text-sm wrap-anywhere text-muted-foreground"
                >
                    {mutation.confirmed
                        ? 'The change is saved. Refresh its result before editing again.'
                        : 'The result is unknown. Check the original save before editing again.'}
                </p>
            )}
            <div className="grid grid-cols-1 gap-2 border-t pt-3">
                <Label>Who can reach this project</Label>
                <ProjectAccess project={project} assignable={assignable} />
            </div>
        </form>
    );
}

function NewProjectForm({ capability }: { capability: ProjectCreate }) {
    const form = useForm({ name: '', description: '' });

    const mutation = useProjectWrite();

    return (
        <form
            className="grid grid-cols-1 gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                void mutation
                    .write('POST', capability.url, {
                        ...form.data,
                        description: form.data.description || null,
                        company_id: capability.company_id,
                        expected_version: capability.expected_version,
                        confirm: true,
                    })
                    .then((saved) => {
                        if (saved) {
                            form.reset();
                        }
                    });
            }}
        >
            <div className="grid max-w-md grid-cols-1 gap-2">
                <Label htmlFor="new-project-name">New project</Label>
                <Input
                    disabled={mutation.busy || mutation.unresolved}
                    id="new-project-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    placeholder="Project name"
                />
                {form.errors.name !== undefined && (
                    <p className="text-sm text-destructive">
                        {form.errors.name}
                    </p>
                )}
            </div>
            {mutation.failure && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {mutation.failure}
                </p>
            )}
            {mutation.unresolved && (
                <p
                    role="status"
                    className="text-sm wrap-anywhere text-muted-foreground"
                >
                    {mutation.confirmed
                        ? 'The project is created. Refresh its result before creating another.'
                        : 'The result is unknown. Check the original creation before editing again.'}
                </p>
            )}
            <div>
                <Button type="submit" disabled={mutation.busy}>
                    {mutation.unresolved
                        ? 'Check project creation'
                        : 'Add project'}
                </Button>
            </div>
        </form>
    );
}

export default function ClientManage({
    company,
    projects,
    project_create: projectCreate,
    assignable,
    portal,
    payment_reminders: paymentReminders = null,
    company_profile: companyProfile,
    profile_action: profileAction,
    contacts = [],
    contact_create_action: contactCreateAction,
}: {
    workspace: { id: string };
    company: ManagedCompany;
    company_profile?: CompanyProfileData;
    profile_action?: string;
    contacts?: ClientContact[];
    contact_create_action?: string;
    projects: ManagedProject[];
    project_create: ProjectCreate;
    assignable: AssignableMember[];
    portal: PortalAccessProps['portal'];
    /** The client's automatic payment reminder cadence (#438). */
    payment_reminders?: CompanyRemindersState | null;
}) {
    return (
        <WorkspaceShell activeModule="home">
            <Head title={`${company.name} settings`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                {/*
                    Same treatment as the heading on client Home, and for the
                    same reason: this one interpolates a client name, and a
                    registered company name is routinely one unbroken run.
                */}
                <h1 className="min-w-0 text-2xl font-semibold tracking-tight wrap-anywhere">
                    {company.name} settings
                </h1>
                <Card>
                    <CardHeader>
                        <CardTitle>Client</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <CompanyForm key={company.id} company={company} />
                    </CardContent>
                </Card>

                {companyProfile && profileAction && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Company details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <CompanyProfile
                                key={company.id}
                                profile={companyProfile}
                                action={profileAction}
                                version={company.version}
                            />
                        </CardContent>
                    </Card>
                )}
                {contactCreateAction && (
                    <Card>
                        <CardHeader>
                            <CardTitle>People</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ClientContacts
                                contacts={contacts}
                                createAction={contactCreateAction}
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Projects</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4">
                        {projects.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                This client has no projects yet.
                            </p>
                        ) : (
                            projects.map((project) => (
                                <ProjectForm
                                    key={project.id}
                                    project={project}
                                    assignable={assignable}
                                />
                            ))
                        )}
                        <NewProjectForm capability={projectCreate} />
                    </CardContent>
                </Card>

                {paymentReminders !== null && (
                    <PaymentReminderCadenceCard cadence={paymentReminders} />
                )}

                {company.square_customer !== undefined && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Square</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-1 text-sm">
                            {company.square_customer === null ? (
                                <p className="text-muted-foreground">
                                    No Square customer is confirmed for this
                                    client.
                                </p>
                            ) : (
                                <dl className="grid grid-cols-1 gap-1">
                                    <div className="flex min-w-0 flex-wrap gap-2">
                                        <dt className="text-muted-foreground">
                                            Square customer
                                        </dt>
                                        <dd className="min-w-0 font-mono wrap-anywhere">
                                            {
                                                company.square_customer
                                                    .square_customer_id
                                            }
                                        </dd>
                                    </div>
                                    <div className="flex min-w-0 flex-wrap gap-2">
                                        <dt className="text-muted-foreground">
                                            Confirmed
                                        </dt>
                                        <dd className="min-w-0 wrap-anywhere">
                                            {formatTimestamp(
                                                company.square_customer
                                                    .confirmed_at,
                                            )}
                                            {company.square_customer
                                                .confirmed_by !== null &&
                                                ` by ${company.square_customer.confirmed_by}`}
                                        </dd>
                                    </div>
                                </dl>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Portal access</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PortalAccess
                            clientName={company.name}
                            portal={portal}
                            projects={projects.map((project) => ({
                                id: project.id,
                                name: project.name,
                            }))}
                        />
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
