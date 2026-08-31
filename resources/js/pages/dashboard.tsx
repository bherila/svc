import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppearanceSelector } from '@/components/appearance-selector';

type Task = {
    id: string;
    title: string;
    status: 'open' | 'in_progress' | 'completed';
    is_visible_to_client: boolean;
};

type Project = {
    id: string;
    name: string;
    status: string;
    is_visible_to_client: boolean;
    tasks: Task[];
};

type Client = {
    id: string;
    name: string;
    billing_email: string | null;
    portal_url: string;
    projects: Project[];
};

type Workspace = {
    id: string;
    name: string;
    role: string;
    operations_url: string;
    time_url: string;
    clients: Client[];
};

const inputClass =
    'w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 outline-none focus:border-cyan-600';
const buttonClass =
    'rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50';

function NewClientForm({ workspaceId }: { workspaceId: string }) {
    const form = useForm({ name: '', billing_email: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/workspaces/${workspaceId}/clients`, {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-2 rounded-2xl border border-dashed border-slate-300 p-4 sm:grid-cols-[1fr_1fr_auto]"
        >
            <input
                className={inputClass}
                placeholder="Client name"
                value={form.data.name}
                onChange={(event) => form.setData('name', event.target.value)}
                required
            />
            <input
                className={inputClass}
                placeholder="Billing email (optional)"
                type="email"
                value={form.data.billing_email}
                onChange={(event) =>
                    form.setData('billing_email', event.target.value)
                }
            />
            <button className={buttonClass} disabled={form.processing}>
                Add client
            </button>
        </form>
    );
}

function NewProjectForm({
    workspaceId,
    clientId,
}: {
    workspaceId: string;
    clientId: string;
}) {
    const form = useForm({
        name: '',
        description: '',
        is_visible_to_client: true,
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/workspaces/${workspaceId}/clients/${clientId}/projects`, {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex gap-2">
            <input
                className={inputClass}
                placeholder="New project"
                value={form.data.name}
                onChange={(event) => form.setData('name', event.target.value)}
                required
            />
            <button className={buttonClass} disabled={form.processing}>
                Add
            </button>
        </form>
    );
}

function NewTaskForm({
    workspaceId,
    projectId,
}: {
    workspaceId: string;
    projectId: string;
}) {
    const form = useForm({
        title: '',
        description: '',
        is_visible_to_client: true,
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/workspaces/${workspaceId}/projects/${projectId}/tasks`, {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="mt-2 flex gap-2">
            <input
                className={inputClass}
                placeholder="New task"
                value={form.data.title}
                onChange={(event) => form.setData('title', event.target.value)}
                required
            />
            <button className={buttonClass} disabled={form.processing}>
                Add
            </button>
        </form>
    );
}

export default function Dashboard({ workspaces }: { workspaces: Workspace[] }) {
    const { auth, applications } = usePage().props;
    const workspaceForm = useForm({ name: '' });
    const createWorkspace = (event: FormEvent) => {
        event.preventDefault();
        workspaceForm.post('/workspaces', {
            onSuccess: () => workspaceForm.reset(),
        });
    };

    return (
        <>
            <Head title="Workspace" />
            <main className="min-h-screen bg-slate-100 text-slate-950">
                <header className="border-b border-slate-200 bg-white">
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                        <Link
                            href="/"
                            className="font-semibold tracking-[0.2em]"
                        >
                            SVC
                        </Link>
                        <div className="flex items-center gap-4 text-sm">
                            <span className="text-slate-600">
                                {auth.user?.name}
                            </span>
                            {/* The sibling applications the identity provider reported at
                                sign-in. A native disclosure keeps this to markup, and the
                                list arrives per request as a shared prop rather than being
                                compiled in, so what exists is not readable from the bundle. */}
                            {applications.length > 0 && (
                                <details className="relative">
                                    <summary className="cursor-pointer list-none font-medium text-slate-600 hover:text-slate-950">
                                        Apps
                                    </summary>
                                    <div className="absolute right-0 z-50 mt-2 min-w-44 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                                        {applications.map((app) => (
                                            <a
                                                key={app.key}
                                                href={app.url}
                                                className="block rounded-lg px-3 py-1.5 whitespace-nowrap text-slate-600 hover:bg-slate-100 hover:text-slate-950"
                                            >
                                                {app.name}
                                            </a>
                                        ))}
                                    </div>
                                </details>
                            )}
                            <AppearanceSelector />
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="font-medium"
                            >
                                Sign out
                            </Link>
                        </div>
                    </div>
                </header>

                <div className="mx-auto max-w-6xl space-y-8 px-6 py-10">
                    <div>
                        <p className="text-sm font-semibold text-cyan-700 uppercase">
                            Operations
                        </p>
                        <h1 className="mt-1 text-4xl font-semibold tracking-tight">
                            Client workspaces
                        </h1>
                    </div>

                    {workspaces.length === 0 && (
                        <section className="rounded-3xl bg-white p-8 shadow-sm">
                            <h2 className="text-xl font-semibold">
                                Create your first workspace
                            </h2>
                            <p className="mt-2 text-slate-600">
                                A workspace is the isolation boundary for one
                                service business.
                            </p>
                            <form
                                onSubmit={createWorkspace}
                                className="mt-5 flex max-w-xl gap-2"
                            >
                                <input
                                    className={inputClass}
                                    placeholder="Business name"
                                    value={workspaceForm.data.name}
                                    onChange={(event) =>
                                        workspaceForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <button
                                    className={buttonClass}
                                    disabled={workspaceForm.processing}
                                >
                                    Create
                                </button>
                            </form>
                        </section>
                    )}

                    {workspaces.map((workspace) => (
                        <section
                            key={workspace.id}
                            className="rounded-3xl bg-white p-6 shadow-sm sm:p-8"
                        >
                            <div className="flex items-center justify-between gap-4">
                                <h2 className="text-2xl font-semibold">
                                    {workspace.name}
                                </h2>
                                <div className="flex items-center gap-3">
                                    <Link
                                        href={workspace.time_url}
                                        className="text-sm font-semibold text-cyan-700"
                                    >
                                        Time
                                    </Link>
                                    <Link
                                        href={workspace.operations_url}
                                        className="text-sm font-semibold text-cyan-700"
                                    >
                                        Proposals &amp; billing
                                    </Link>
                                    <span className="rounded-full bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-800">
                                        {workspace.role}
                                    </span>
                                </div>
                            </div>
                            <div className="mt-6 space-y-5">
                                {workspace.clients.map((client) => (
                                    <article
                                        key={client.id}
                                        className="rounded-2xl border border-slate-200 p-5"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <h3 className="text-lg font-semibold">
                                                    {client.name}
                                                </h3>
                                                {client.billing_email && (
                                                    <p className="text-sm text-slate-500">
                                                        {client.billing_email}
                                                    </p>
                                                )}
                                            </div>
                                            <Link
                                                href={client.portal_url}
                                                className="text-sm font-semibold text-cyan-700"
                                            >
                                                Open portal
                                            </Link>
                                        </div>
                                        <div className="mt-4 grid gap-3 lg:grid-cols-2">
                                            {client.projects.map((project) => (
                                                <div
                                                    key={project.id}
                                                    className="rounded-xl bg-slate-50 p-4"
                                                >
                                                    <div className="flex justify-between gap-3">
                                                        <h4 className="font-semibold">
                                                            {project.name}
                                                        </h4>
                                                        <span className="text-xs text-slate-500">
                                                            {project.status}
                                                        </span>
                                                    </div>
                                                    <ul className="mt-3 space-y-2">
                                                        {project.tasks.map(
                                                            (task) => (
                                                                <li
                                                                    key={
                                                                        task.id
                                                                    }
                                                                    className="flex items-center justify-between gap-3 text-sm"
                                                                >
                                                                    <span
                                                                        className={
                                                                            task.status ===
                                                                            'completed'
                                                                                ? 'text-slate-400 line-through'
                                                                                : ''
                                                                        }
                                                                    >
                                                                        {
                                                                            task.title
                                                                        }
                                                                    </span>
                                                                    <button
                                                                        className="font-medium text-cyan-700"
                                                                        onClick={() =>
                                                                            router.patch(
                                                                                `/workspaces/${workspace.id}/tasks/${task.id}`,
                                                                                {
                                                                                    status:
                                                                                        task.status ===
                                                                                        'completed'
                                                                                            ? 'open'
                                                                                            : 'completed',
                                                                                    is_visible_to_client:
                                                                                        task.is_visible_to_client,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        {task.status ===
                                                                        'completed'
                                                                            ? 'Reopen'
                                                                            : 'Complete'}
                                                                    </button>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                    <NewTaskForm
                                                        workspaceId={
                                                            workspace.id
                                                        }
                                                        projectId={project.id}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                        <NewProjectForm
                                            workspaceId={workspace.id}
                                            clientId={client.id}
                                        />
                                    </article>
                                ))}
                                <NewClientForm workspaceId={workspace.id} />
                            </div>
                        </section>
                    ))}
                </div>
            </main>
        </>
    );
}
