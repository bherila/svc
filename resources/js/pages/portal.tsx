import { Head, Link, usePage } from '@inertiajs/react';

type PortalCompany = {
    name: string;
    projects: Array<{
        id: string;
        name: string;
        description: string | null;
        status: string;
        tasks: Array<{
            id: string;
            title: string;
            description: string | null;
            status: string;
        }>;
    }>;
};

export default function Portal({ company }: { company: PortalCompany }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title={`${company.name} portal`} />
            <main className="min-h-screen bg-slate-950 text-white">
                <div className="mx-auto max-w-5xl px-6 py-8">
                    <header className="flex items-center justify-between border-b border-white/10 pb-6">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.2em] text-cyan-300 uppercase">
                                SVC client portal
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold">
                                {company.name}
                            </h1>
                        </div>
                        <div className="text-right text-sm text-slate-300">
                            <p>{auth.user?.name}</p>
                            <Link
                                href="/"
                                className="font-semibold text-cyan-300"
                            >
                                SVC home
                            </Link>
                        </div>
                    </header>

                    <section className="mt-10 grid gap-5 md:grid-cols-2">
                        {company.projects.map((project) => (
                            <article
                                key={project.id}
                                className="rounded-3xl border border-white/10 bg-white/5 p-6"
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <h2 className="text-xl font-semibold">
                                        {project.name}
                                    </h2>
                                    <span className="rounded-full bg-cyan-300/10 px-3 py-1 text-xs text-cyan-200">
                                        {project.status}
                                    </span>
                                </div>
                                {project.description && (
                                    <p className="mt-3 text-sm leading-6 text-slate-300">
                                        {project.description}
                                    </p>
                                )}
                                <ul className="mt-5 space-y-3">
                                    {project.tasks.map((task) => (
                                        <li
                                            key={task.id}
                                            className="rounded-xl bg-slate-900/80 p-4"
                                        >
                                            <div className="flex justify-between gap-3">
                                                <span className="font-medium">
                                                    {task.title}
                                                </span>
                                                <span className="text-xs text-slate-400">
                                                    {task.status.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </span>
                                            </div>
                                            {task.description && (
                                                <p className="mt-2 text-sm text-slate-400">
                                                    {task.description}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </article>
                        ))}
                    </section>
                </div>
            </main>
        </>
    );
}
