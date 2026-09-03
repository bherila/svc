import { Head, Link, useForm } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

/**
 * One proposal, and - for the client it was sent to - the decision on it.
 *
 * Acceptance used to sit inline on the portal's home screen, among cards for
 * every project, invoice and agreement the client had. Signing your name is a
 * decision, and a decision offered beside ten other things is one taken without
 * reading it. It gets its own page here, reached from a single line on Home
 * saying something is waiting.
 *
 * The same page serves the operator, who reads it and cannot accept it. Whether
 * the form appears is decided on the server and carried as `accept_href`: the
 * action behind it authorizes independently, because a form nobody rendered is
 * not an authorization check.
 */

type ProposalItem = {
    id: string;
    description: string;
    quantity: string;
    unit_amount: number;
    cadence: string | null;
};

export default function ClientProposalDetail({
    company,
    home_href: homeHref,
    proposal,
    items,
    accept_href: acceptHref,
}: {
    company: { id: string; name: string };
    home_href: string;
    proposal: {
        id: string;
        title: string;
        summary: string | null;
        terms: string | null;
        status: string;
        currency: string;
        valid_until: string | null;
        sent_at: string | null;
        accepted_at: string | null;
        total_amount: number;
    };
    items: ProposalItem[];
    accept_href: string | null;
}) {
    const form = useForm({ signer_name: '', signer_title: '' });

    return (
        <WorkspaceShell activeModule="home">
            <Head title={proposal.title} />

            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <header className="grid grid-cols-1 gap-1">
                    <Link
                        href={homeHref}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← {company.name}
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {proposal.title}
                        </h1>
                        <Badge variant="outline">
                            {statusLabel(proposal.status)}
                        </Badge>
                    </div>
                </header>

                {proposal.summary !== null && (
                    <p className="text-sm whitespace-pre-line">
                        {proposal.summary}
                    </p>
                )}

                <section>
                    <h2 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        What is proposed
                    </h2>
                    <div className="mt-3 overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="min-w-64">
                                        Description
                                    </TableHead>
                                    <TableHead>Cadence</TableHead>
                                    <TableHead>Quantity</TableHead>
                                    <TableHead>Unit</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                            {item.description}
                                        </TableCell>
                                        <TableCell>
                                            {item.cadence ?? 'One time'}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {item.quantity}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatMoney(
                                                item.unit_amount,
                                                proposal.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <p className="mt-3 text-sm">
                        Total{' '}
                        <span className="font-medium tabular-nums">
                            {formatMoney(
                                proposal.total_amount,
                                proposal.currency,
                            )}
                        </span>
                        {proposal.valid_until !== null && (
                            <span className="text-muted-foreground">
                                {' '}
                                · valid until {proposal.valid_until}
                            </span>
                        )}
                    </p>
                </section>

                {proposal.terms !== null && (
                    <section>
                        <h2 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                            Terms
                        </h2>
                        <p className="mt-3 text-sm whitespace-pre-line">
                            {proposal.terms}
                        </p>
                    </section>
                )}

                {acceptHref !== null && (
                    <section className="rounded-lg border border-border p-4">
                        <h2 className="font-medium">Accept this proposal</h2>
                        <form
                            className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(acceptHref);
                            }}
                        >
                            <div className="grid grid-cols-1 gap-2">
                                <Label htmlFor="signer-name">Your name</Label>
                                <Input
                                    id="signer-name"
                                    value={form.data.signer_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'signer_name',
                                            event.target.value,
                                        )
                                    }
                                />
                                {form.errors.signer_name !== undefined && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.signer_name}
                                    </p>
                                )}
                            </div>
                            <div className="grid grid-cols-1 gap-2">
                                <Label htmlFor="signer-title">Your title</Label>
                                <Input
                                    id="signer-title"
                                    value={form.data.signer_title}
                                    onChange={(event) =>
                                        form.setData(
                                            'signer_title',
                                            event.target.value,
                                        )
                                    }
                                />
                                {form.errors.signer_title !== undefined && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.signer_title}
                                    </p>
                                )}
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                Accept
                            </Button>
                        </form>
                    </section>
                )}

                {proposal.accepted_at !== null && (
                    <p className="text-sm text-muted-foreground">
                        Accepted {formatTimestamp(proposal.accepted_at)}.
                    </p>
                )}
            </main>
        </WorkspaceShell>
    );
}
