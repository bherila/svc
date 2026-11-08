import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { OptionSelect } from '@/components/ui/option-select';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { HistorySummary } from '@/types/history';

export default function HistoryIndex({
    entries,
    search_href: searchHref,
    filters,
    kinds,
    next_href: nextHref,
    previous_href: previousHref,
}: {
    entries: HistorySummary[];
    search_href: string;
    filters: { q: string; kind: string; company: string; parent: string };
    kinds: { value: string; label: string }[];
    next_href: string | null;
    previous_href: string | null;
}) {
    const form = useForm(filters);

    return (
        <WorkspaceShell>
            <Head title="History" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <div className="min-w-0">
                    <h1 className="text-2xl font-semibold">History</h1>
                    <p className="text-sm text-muted-foreground">
                        Preserved records are read-only. Historical invoices and
                        tasks do not enter current billing or work queues.
                    </p>
                </div>
                <form
                    className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(searchHref, { preserveState: true });
                    }}
                >
                    <div className="grid min-w-0 grid-cols-1 gap-2">
                        <Label htmlFor="history-search">Search history</Label>
                        <Input
                            id="history-search"
                            value={form.data.q}
                            onChange={(event) =>
                                form.setData('q', event.target.value)
                            }
                            maxLength={200}
                        />
                    </div>
                    <div className="grid min-w-0 grid-cols-1 gap-2">
                        <Label htmlFor="history-kind">Record type</Label>
                        <OptionSelect
                            id="history-kind"
                            value={form.data.kind}
                            options={[
                                { value: '', label: 'All types' },
                                ...kinds.map((kind) => ({
                                    value: kind.value,
                                    label: kind.label,
                                })),
                            ]}
                            onValueChange={(next) =>
                                form.setData('kind', next)
                            }
                        />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Search
                    </Button>
                </form>
                <div className="grid min-w-0 grid-cols-1 gap-3">
                    {entries.length === 0 && (
                        <p className="text-muted-foreground">
                            No historical records match.
                        </p>
                    )}
                    {entries.map((entry) => (
                        <Card key={entry.id} className="min-w-0">
                            <CardContent>
                                <article>
                                    <p className="text-xs text-muted-foreground">
                                        {entry.kind}
                                    </p>
                                    <Link
                                        href={entry.href}
                                        className="font-medium wrap-anywhere underline underline-offset-4"
                                    >
                                        {entry.title}
                                    </Link>
                                    <p className="text-sm wrap-anywhere text-muted-foreground">
                                        {[entry.occurred_on, entry.author]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </article>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <nav aria-label="History pages" className="flex gap-4">
                    {previousHref && (
                        <Link href={previousHref} className="underline">
                            Previous
                        </Link>
                    )}
                    {nextHref && (
                        <Link href={nextHref} className="underline">
                            Next
                        </Link>
                    )}
                </nav>
            </main>
        </WorkspaceShell>
    );
}
