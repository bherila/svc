import { Head, Link } from '@inertiajs/react';
import { PublicLayout } from '@/components/packages/public-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatMoney } from '@/lib/money';
import { priceLine } from '@/lib/packages';
import type {
    PublicCatalogProps,
    PublicPackageSummary,
} from '@/types/packages';

function PackageCard({ pkg }: { pkg: PublicPackageSummary }) {
    return (
        <Card data-slot="public-package">
            <CardHeader>
                <CardTitle className="wrap-anywhere">{pkg.name}</CardTitle>
                {pkg.summary !== null && (
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        {pkg.summary}
                    </p>
                )}
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-3">
                <p className="text-xl font-semibold tabular-nums">
                    {pkg.cycle_amount > 0
                        ? priceLine(
                              pkg.cycle_amount,
                              pkg.currency,
                              pkg.billing_cadence,
                          )
                        : 'Priced as described on its page'}
                </p>
                {(pkg.setup_fee_amount !== null ||
                    pkg.minimum_term_months !== null) && (
                    <p className="text-sm text-muted-foreground">
                        {[
                            pkg.setup_fee_amount === null
                                ? null
                                : `Setup ${formatMoney(pkg.setup_fee_amount, pkg.currency)}`,
                            pkg.minimum_term_months === null
                                ? null
                                : `${pkg.minimum_term_months}-month minimum`,
                        ]
                            .filter((part) => part !== null)
                            .join(' · ')}
                    </p>
                )}
                <div>
                    <Link
                        href={pkg.url}
                        aria-label={`See details: ${pkg.name}`}
                        className="inline-flex min-h-11 items-center text-sm font-medium underline underline-offset-4 md:min-h-8"
                    >
                        See details
                    </Link>
                </div>
            </CardContent>
        </Card>
    );
}

export default function PublicPackageCatalog({
    workspace,
    packages,
    sign_in_url: signInUrl,
}: PublicCatalogProps) {
    return (
        <PublicLayout
            workspaceName={workspace.name}
            catalogUrl={null}
            signInUrl={signInUrl}
        >
            <Head title={`Services · ${workspace.name}`} />
            <div className="grid grid-cols-1 gap-2">
                <h1 className="text-2xl font-semibold tracking-tight wrap-anywhere">
                    Services from {workspace.name}
                </h1>
                <p className="text-muted-foreground">
                    Choose a service to see what it includes and what it costs.
                </p>
            </div>
            {packages.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nothing is listed here right now.
                </p>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {packages.map((pkg) => (
                        <PackageCard key={pkg.url} pkg={pkg} />
                    ))}
                </div>
            )}
            <p className="text-sm text-muted-foreground">
                Already a client?{' '}
                <Link href={signInUrl} className="underline underline-offset-4">
                    Sign in
                </Link>{' '}
                to add a service to your account.
            </p>
        </PublicLayout>
    );
}
