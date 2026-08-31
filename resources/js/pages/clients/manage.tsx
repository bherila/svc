import { Head, useForm } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import ClientContextLayout from '@/layouts/client-context-layout';

type ManagedCompany = {
    id: string;
    name: string;
    billing_email: string | null;
    is_active: boolean;
};

type ManagedProject = {
    id: string;
    name: string;
    description: string | null;
    status: string;
    is_visible_to_client: boolean;
};

/**
 * The shape of the client record, edited from the client's own tab.
 *
 * Manage is a tab rather than a parallel admin section, so an operator never
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
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(`/workspaces/${workspaceId}/clients/${company.id}`, {
                    preserveScroll: true,
                });
            }}
        >
            <div className="grid gap-2">
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

            <div className="grid gap-2">
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
}: {
    workspaceId: string;
    companyId: string;
    project: ManagedProject;
}) {
    const form = useForm({
        name: project.name,
        description: project.description ?? '',
        status: project.status,
        is_visible_to_client: project.is_visible_to_client,
    });

    return (
        <form
            className="grid gap-3 rounded-xl border p-4"
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
            className="grid gap-3"
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
            <div className="grid gap-2">
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
}: {
    workspace: { id: string };
    company: ManagedCompany;
    projects: ManagedProject[];
}) {
    return (
        <ClientContextLayout active="manage">
            <Head title={`Manage ${company.name}`} />
            <main className="mx-auto grid max-w-4xl gap-6 p-6">
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
                    <CardContent className="grid gap-4">
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
        </ClientContextLayout>
    );
}
