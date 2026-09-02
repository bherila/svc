import { Head, useForm } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

type ManagedCompany = {
    id: string;
    name: string;
    billing_email: string | null;
    is_active: boolean;
};

type ProjectMember = { user: string; role: string };

type ManagedProject = {
    id: string;
    name: string;
    description: string | null;
    status: string;
    is_visible_to_client: boolean;
    lock_version: number;
    members: ProjectMember[];
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

function ProjectAccess({
    workspaceId,
    companyId,
    project,
    assignable,
}: {
    workspaceId: string;
    companyId: string;
    project: ManagedProject;
    assignable: AssignableMember[];
}) {
    const roleOf = (userId: string): string =>
        project.members.find((member) => member.user === userId)?.role ??
        'none';

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
                    workspaceId={workspaceId}
                    companyId={companyId}
                    projectId={project.id}
                    member={member}
                    role={roleOf(member.id)}
                />
            ))}
        </div>
    );
}

function AccessRow({
    workspaceId,
    companyId,
    projectId,
    member,
    role,
}: {
    workspaceId: string;
    companyId: string;
    projectId: string;
    member: AssignableMember;
    role: string;
}) {
    const form = useForm({ user: member.id, role });

    return (
        <div className="flex flex-wrap items-center gap-3 text-sm">
            <span className="min-w-40">{member.name}</span>
            <select
                aria-label={`Access for ${member.name}`}
                className="h-9 rounded-md border bg-transparent px-2"
                value={form.data.role}
                onChange={(event) => {
                    // `setData` is asynchronous, so the request is transformed
                    // to carry the value that was just chosen rather than
                    // whichever one the state happens to hold when it fires.
                    const next = event.target.value;
                    form.setData('role', next);
                    form.transform((data) => ({ ...data, role: next }));
                    form.put(
                        `/workspaces/${workspaceId}/clients/${companyId}/projects/${projectId}/access`,
                        { preserveScroll: true },
                    );
                }}
            >
                {PROJECT_ROLES.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
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
function CompanyForm({
    workspaceId,
    company,
}: {
    workspaceId: string;
    company: ManagedCompany;
}) {
    const form = useForm({
        name: company.name,
        billing_email: company.billing_email ?? '',
        is_active: company.is_active,
    });

    return (
        <form
            className="grid grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(`/workspaces/${workspaceId}/clients/${company.id}`, {
                    preserveScroll: true,
                });
            }}
        >
            <div className="grid grid-cols-1 gap-2">
                <Label htmlFor="company-name">Name</Label>
                <Input
                    id="company-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                {form.errors.name !== undefined && (
                    <p className="text-sm text-destructive">
                        {form.errors.name}
                    </p>
                )}
            </div>

            <div className="grid grid-cols-1 gap-2">
                <Label htmlFor="company-billing-email">Billing email</Label>
                <Input
                    id="company-billing-email"
                    type="email"
                    value={form.data.billing_email}
                    onChange={(event) =>
                        form.setData('billing_email', event.target.value)
                    }
                    placeholder="Not set"
                />
                {form.errors.billing_email !== undefined && (
                    <p className="text-sm text-destructive">
                        {form.errors.billing_email}
                    </p>
                )}
            </div>

            <div className="flex items-center gap-3">
                <Switch
                    id="company-active"
                    checked={form.data.is_active}
                    onCheckedChange={(next) =>
                        form.setData('is_active', next === true)
                    }
                />
                <Label htmlFor="company-active">Active client</Label>
            </div>

            <div>
                <Button type="submit" disabled={form.processing}>
                    Save client
                </Button>
            </div>
        </form>
    );
}

function ProjectForm({
    workspaceId,
    companyId,
    project,
    assignable,
}: {
    workspaceId: string;
    companyId: string;
    project: ManagedProject;
    assignable: AssignableMember[];
}) {
    const form = useForm({
        name: project.name,
        description: project.description ?? '',
        status: project.status,
        is_visible_to_client: project.is_visible_to_client,
        // Round-tripped unchanged. The server compares it to the row's current
        // version and refuses the save if someone else has written since this
        // form was rendered, which is the only way a stale but well-formed
        // payload can be told from a deliberate one.
        lock_version: project.lock_version,
    });

    return (
        <form
            className="grid grid-cols-1 gap-3 rounded-xl border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(
                    `/workspaces/${workspaceId}/clients/${companyId}/projects/${project.id}`,
                    { preserveScroll: true },
                );
            }}
        >
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    aria-label={`Name of ${project.name}`}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    className="max-w-xs"
                />
                <Badge variant="outline">{project.status}</Badge>
            </div>

            <Textarea
                aria-label={`Description of ${project.name}`}
                value={form.data.description}
                onChange={(event) =>
                    form.setData('description', event.target.value)
                }
                placeholder="No description"
                rows={2}
            />

            {/*
                A refused save has to say so. The conflict case is the reason
                this exists: the request is rejected, Inertia re-renders with
                the operator's edits intact, and without a message on screen
                that is indistinguishable from a save that worked - which is
                the failure the version check was added to prevent, moved one
                step later.
            */}
            {form.errors.lock_version ? (
                <p role="alert" className="text-sm text-destructive">
                    {form.errors.lock_version}
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-6">
                <div className="flex items-center gap-3">
                    <Switch
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
                    disabled={form.processing}
                >
                    Save project
                </Button>
            </div>

            <div className="grid grid-cols-1 gap-2 border-t pt-3">
                <Label>Who can reach this project</Label>
                <ProjectAccess
                    workspaceId={workspaceId}
                    companyId={companyId}
                    project={project}
                    assignable={assignable}
                />
            </div>
        </form>
    );
}

function NewProjectForm({
    workspaceId,
    companyId,
}: {
    workspaceId: string;
    companyId: string;
}) {
    const form = useForm({ name: '', description: '' });

    return (
        <form
            className="grid grid-cols-1 gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(
                    `/workspaces/${workspaceId}/clients/${companyId}/projects`,
                    {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    },
                );
            }}
        >
            <div className="grid grid-cols-1 gap-2">
                <Label htmlFor="new-project-name">New project</Label>
                <Input
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
            <div>
                <Button type="submit" disabled={form.processing}>
                    Add project
                </Button>
            </div>
        </form>
    );
}

export default function ClientManage({
    workspace,
    company,
    projects,
    assignable,
}: {
    workspace: { id: string };
    company: ManagedCompany;
    projects: ManagedProject[];
    assignable: AssignableMember[];
}) {
    return (
        <WorkspaceShell activeModule="home">
            <Head title={`${company.name} settings`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <h1 className="text-2xl font-semibold tracking-tight">
                    {company.name} settings
                </h1>
                <Card>
                    <CardHeader>
                        <CardTitle>Client</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <CompanyForm
                            workspaceId={workspace.id}
                            company={company}
                        />
                    </CardContent>
                </Card>

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
                                    workspaceId={workspace.id}
                                    companyId={company.id}
                                    project={project}
                                    assignable={assignable}
                                />
                            ))
                        )}
                        <NewProjectForm
                            workspaceId={workspace.id}
                            companyId={company.id}
                        />
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
