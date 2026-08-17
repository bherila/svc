import { Head, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Client-service operations" />
            <main className="min-h-screen bg-slate-950 text-white">
                <div className="mx-auto flex min-h-screen max-w-6xl flex-col px-6 py-8">
                    <nav className="flex items-center justify-between">
                        <span className="text-xl font-semibold tracking-[0.22em]">
                            SVC
                        </span>
                        <a
                            href={auth.user ? '/app' : '/oauth/redirect'}
                            className="rounded-full border border-white/20 px-5 py-2 text-sm font-medium hover:bg-white/10"
                        >
                            {auth.user ? 'Open workspace' : 'Sign in'}
                        </a>
                    </nav>

                    <section className="grid flex-1 items-center gap-12 py-20 lg:grid-cols-[1.2fr_0.8fr]">
                        <div>
                            <p className="mb-5 text-sm font-semibold tracking-[0.2em] text-cyan-300 uppercase">
                                Service Venture Console
                            </p>
                            <h1 className="max-w-3xl text-5xl leading-tight font-semibold tracking-tight sm:text-7xl">
                                Run client work without running five different
                                systems.
                            </h1>
                            <p className="mt-7 max-w-2xl text-lg leading-8 text-slate-300">
                                Clients, projects, agreements, time, invoices,
                                payments, and files in one tenant-safe workspace
                                for independent service businesses.
                            </p>
                            <a
                                href={auth.user ? '/app' : '/oauth/redirect'}
                                className="mt-9 inline-flex rounded-full bg-cyan-300 px-6 py-3 font-semibold text-slate-950 hover:bg-cyan-200"
                            >
                                {auth.user
                                    ? 'Continue to SVC'
                                    : 'Sign in to SVC'}
                            </a>
                        </div>

                        <div className="rounded-3xl border border-white/10 bg-white/5 p-7 shadow-2xl shadow-cyan-950/30 backdrop-blur">
                            <p className="text-sm text-slate-400">
                                Foundation roadmap
                            </p>
                            <ol className="mt-5 space-y-4">
                                {[
                                    ['01', 'Workspaces and client access'],
                                    ['02', 'Projects, tasks, and time'],
                                    ['03', 'Agreements and billing'],
                                    ['04', 'Files and integrations'],
                                ].map(([number, label]) => (
                                    <li
                                        key={number}
                                        className="flex items-center gap-4 rounded-2xl bg-slate-900/70 p-4"
                                    >
                                        <span className="font-mono text-sm text-cyan-300">
                                            {number}
                                        </span>
                                        <span>{label}</span>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
