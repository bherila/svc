import { Head, usePage } from '@inertiajs/react';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Client-service operations" />
            <main className="relative isolate min-h-screen overflow-hidden bg-slate-950 text-white">
                <svg
                    aria-hidden="true"
                    viewBox="0 0 200 200"
                    className="pointer-events-none absolute -top-24 -right-24 h-auto w-[22rem] rotate-12 text-cyan-300 opacity-[0.05] sm:w-[34rem]"
                >
                    <mask id="svc-gear-hole">
                        <rect width="200" height="200" fill="white" />
                        <circle cx="100" cy="100" r="30" fill="black" />
                    </mask>
                    <g fill="currentColor" mask="url(#svc-gear-hole)">
                        <circle cx="100" cy="100" r="72" />
                        {Array.from({ length: 12 }, (_, index) => (
                            <rect
                                key={index}
                                x="87"
                                y="12"
                                width="26"
                                height="34"
                                rx="6"
                                transform={`rotate(${index * 30} 100 100)`}
                            />
                        ))}
                    </g>
                </svg>
                <div className="relative z-10 mx-auto flex min-h-screen max-w-6xl flex-col px-6 py-8">
                    <nav className="flex items-center justify-between">
                        <span className="text-xl font-semibold tracking-[0.22em]">
                            SVC
                        </span>
                        <a
                            href={auth.user ? '/app' : '/oauth/redirect'}
                            className={cn(
                                buttonVariants({
                                    variant: 'outline',
                                    size: 'lg',
                                }),
                                'border-white/20 bg-transparent text-white hover:bg-white/10 hover:text-white',
                            )}
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

                        <Card className="bg-white/5 text-white shadow-2xl shadow-cyan-950/30 ring-white/10 backdrop-blur">
                            <CardContent>
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
                            </CardContent>
                        </Card>
                    </section>
                </div>
            </main>
        </>
    );
}
