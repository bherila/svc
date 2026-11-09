import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatTimestampDate } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cadencePer, paymentStateLabel, priceLine } from '@/lib/packages';
import { cn } from '@/lib/utils';
import type {
    PortalAddablePackage,
    PortalService,
    PortalServicesProps,
} from '@/types/packages';

/** Offers waiting for a signature and signed ones waiting for review are the reader's next move. */
function actionLabel(status: string): string {
    return status === 'offered' ? 'Review and sign' : 'View';
}

function AddCard({ pkg }: { pkg: PortalAddablePackage }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // The server answers a successful add with a redirect to the review page,
    // which Inertia follows; a refusal comes back as a validation error.
    const add = () => {
        if (pkg.add_url === null) {
            return;
        }

        setBusy(true);
        setError(null);
        router.post(
            pkg.add_url,
            { package: pkg.id },
            {
                onError: (errors) =>
                    setError(
                        errors.package ??
                            Object.values(errors)[0] ??
                            'That could not be added.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <li className="min-w-0">
            <Card aria-label={pkg.name} className="h-full">
                <CardHeader>
                    <CardTitle className="wrap-anywhere">{pkg.name}</CardTitle>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-3">
                    <p className="text-sm tabular-nums">
                        <span className="text-lg font-semibold">
                            {formatMoney(pkg.cycle_amount, pkg.currency)}
                        </span>{' '}
                        <span className="text-muted-foreground">
                            {cadencePer(pkg.billing_cadence)}
                            {pkg.setup_fee_amount === null
                                ? ''
                                : ` · setup ${formatMoney(pkg.setup_fee_amount, pkg.currency)}`}
                        </span>
                    </p>
                    {pkg.summary !== null && (
                        <p className="text-sm wrap-anywhere text-muted-foreground">
                            {pkg.summary}
                        </p>
                    )}
                    {pkg.add_url === null ? (
                        <p className="text-sm wrap-anywhere text-muted-foreground">
                            {pkg.add_refusal?.message ??
                                'This cannot be added from here.'}
                        </p>
                    ) : (
                        <div className="grid grid-cols-1 gap-2">
                            <div>
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={add}
                                >
                                    Review and add…
                                </Button>
                            </div>
                            {error !== null && (
                                <p
                                    role="alert"
                                    className="text-sm wrap-anywhere text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>
        </li>
    );
}

function ServiceRow({ service }: { service: PortalService }) {
    const since =
        service.activated_at ?? service.acceptance?.accepted_at ?? null;
    const { terms } = service;
    const paymentState =
        service.acceptance === null
            ? null
            : paymentStateLabel(service.payment.state);

    return (
        <TableRow>
            <TableCell className="max-w-0 min-w-48 whitespace-normal">
                <Link
                    href={service.url}
                    className="font-medium wrap-anywhere underline-offset-4 hover:underline"
                >
                    {service.name}
                </Link>
                {service.version_changed && (
                    <p className="text-xs text-muted-foreground">
                        Signed on updated terms
                    </p>
                )}
            </TableCell>
            <TableCell className="tabular-nums">
                {priceLine(
                    terms.cycle_amount,
                    terms.currency,
                    terms.billing_cadence,
                )}
            </TableCell>
            <TableCell>
                {since === null ? '—' : formatTimestampDate(since)}
            </TableCell>
            <TableCell>
                <div className="flex flex-wrap items-center gap-1">
                    <Badge variant="outline">
                        {statusLabel(service.status)}
                    </Badge>
                    {paymentState !== null && (
                        <Badge variant="outline">{paymentState}</Badge>
                    )}
                </div>
            </TableCell>
            <TableCell className="text-right">
                <Link
                    href={service.url}
                    className="text-sm underline underline-offset-4"
                >
                    {actionLabel(service.status)}
                    <span className="sr-only"> {service.name}</span>
                </Link>
            </TableCell>
        </TableRow>
    );
}

/**
 * The client's Services tab: what they have, and - for a company-wide contact -
 * what they could add. Adding makes an offer and lands on its review page; it
 * starts nothing by itself.
 */
export default function PortalServices({
    company,
    services,
    addable_packages: addable,
}: PortalServicesProps) {
    return (
        <WorkspaceShell activeModule="services">
            <Head title={`Services · ${company.name}`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-8 py-8')}
            >
                <h1 className="sr-only">Services</h1>

                <section
                    aria-labelledby="your-services"
                    className="grid grid-cols-1 gap-3"
                >
                    <h2 id="your-services" className="text-lg font-semibold">
                        Your services
                    </h2>
                    {services.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            You have no services yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="min-w-48">
                                            Service
                                        </TableHead>
                                        <TableHead>Price</TableHead>
                                        <TableHead>Since</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Open
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {services.map((service) => (
                                        <ServiceRow
                                            key={service.id}
                                            service={service}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>

                {addable !== null && addable.length > 0 && (
                    <section
                        aria-labelledby="add-service"
                        className="grid grid-cols-1 gap-3"
                    >
                        <h2 id="add-service" className="text-lg font-semibold">
                            Add a service
                        </h2>
                        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {addable.map((pkg) => (
                                <AddCard key={pkg.id} pkg={pkg} />
                            ))}
                        </ul>
                    </section>
                )}
            </main>
        </WorkspaceShell>
    );
}
