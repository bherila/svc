import { Head } from '@inertiajs/react';
import { PortalAutopayCard } from '@/components/billing/autopay-portal';
import type { PortalAutopay } from '@/components/billing/autopay-portal';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

type SavedMethod = {
    id: string;
    label: string;
    type: string;
    exp_month: number | null;
    exp_year: number | null;
    is_default: boolean;
};

/**
 * The client's billing page: autopay, and the payment methods saved for it.
 *
 * Methods are saved when an invoice is paid with a card the client chooses to
 * keep; this page shows them and which one is the default, because the default
 * is what autopay charges. Everything here is read from the server: the
 * viewer's ability to change autopay arrives as URLs, or null.
 */
export default function PortalBilling({
    provider_name: providerName,
    autopay,
    autopay_available: available,
    payment_methods: methods,
    result = null,
}: {
    company: { id: string; name: string };
    provider_name: string;
    home_href: string;
    autopay_available: boolean;
    autopay: PortalAutopay;
    payment_methods: SavedMethod[];
    result?: string | null;
}) {
    return (
        <WorkspaceShell activeModule="billing">
            <Head title="Billing" />
            <main
                className={cn(
                    SHELL_CONTAINER,
                    'grid grid-cols-1 items-start gap-6 py-8 lg:grid-cols-2',
                )}
            >
                <PortalAutopayCard
                    providerName={providerName}
                    autopay={autopay}
                    available={available}
                    result={result}
                />

                <Card aria-labelledby="methods-heading">
                    <CardHeader>
                        <CardTitle
                            id="methods-heading"
                            role="heading"
                            aria-level={2}
                            className="text-lg"
                        >
                            Payment methods
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4 text-sm">
                        {methods.length === 0 ? (
                            <p className="text-muted-foreground">
                                No payment method is saved. Save a card when you
                                pay an invoice to use it for autopay.
                            </p>
                        ) : (
                            <ul className="grid grid-cols-1 gap-2">
                                {methods.map((method) => (
                                    <li key={method.id}>
                                        <Card
                                            className={cn(
                                                'py-0',
                                                method.is_default &&
                                                    'ring-foreground',
                                            )}
                                        >
                                            <CardContent className="flex min-w-0 flex-wrap items-center justify-between gap-2 py-3">
                                                <div className="grid min-w-0 grid-cols-1 gap-0.5">
                                                    <span className="font-medium wrap-anywhere">
                                                        {method.label}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {method.exp_month !==
                                                            null &&
                                                        method.exp_year !== null
                                                            ? `Expires ${String(method.exp_month).padStart(2, '0')}/${String(method.exp_year).slice(-2)}`
                                                            : 'No expiry'}
                                                        {method.is_default &&
                                                        autopay.enabled
                                                            ? ' · Used for autopay'
                                                            : ''}
                                                    </span>
                                                </div>
                                                {method.is_default && (
                                                    <Badge variant="secondary">
                                                        Default
                                                    </Badge>
                                                )}
                                            </CardContent>
                                        </Card>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Autopay always uses the default method. Removing it
                            turns autopay off until you choose another.
                        </p>
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
