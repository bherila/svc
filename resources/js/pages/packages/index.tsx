import { Head, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { ClientsTabs } from '@/components/packages/clients-tabs';
import { CopyLinkButton } from '@/components/packages/copy-link-button';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cycleAmount, priceLine } from '@/lib/packages';
import { cn } from '@/lib/utils';
import type { PackageListItem, PackagesIndexProps } from '@/types/packages';

/** The terms a row shows: what is live, or the draft of a package never published. */
function shownVersion(pkg: PackageListItem) {
    return pkg.published ?? pkg.draft;
}

function Price({ pkg }: { pkg: PackageListItem }) {
    const version = shownVersion(pkg);

    if (version === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const { terms } = version;
    const cycle = cycleAmount(terms);

    return (
        <div className="grid grid-cols-1 gap-0.5">
            <span className="font-medium tabular-nums">
                {cycle > 0
                    ? priceLine(cycle, terms.currency, terms.billing_cadence)
                    : 'Priced by usage'}
            </span>
            {version.setup_fee_total > 0 && (
                <span className="text-xs text-muted-foreground tabular-nums">
                    setup {formatMoney(version.setup_fee_total, terms.currency)}
                </span>
            )}
        </div>
    );
}

function VersionText({ pkg }: { pkg: PackageListItem }) {
    if (pkg.draft !== null && pkg.published !== null) {
        return (
            <>
                v{pkg.published.number}
                <span className="text-xs text-muted-foreground">
                    {' '}
                    · v{pkg.draft.number} draft
                </span>
            </>
        );
    }

    const version = shownVersion(pkg);

    return version === null ? (
        <>—</>
    ) : (
        <>
            v{version.number}
            {pkg.published === null && (
                <span className="text-xs text-muted-foreground"> draft</span>
            )}
        </>
    );
}

function PackageRow({ pkg }: { pkg: PackageListItem }) {
    const version = shownVersion(pkg);
    const name = version?.terms.name ?? pkg.link_name;

    return (
        <TableRow>
            <TableCell className="max-w-0 whitespace-normal wrap-anywhere">
                <p className="font-medium">{name}</p>
                {version?.terms.summary && (
                    <p className="text-xs text-muted-foreground">
                        {version.terms.summary}
                    </p>
                )}
                <p className="text-xs text-muted-foreground">
                    /{pkg.link_name}
                </p>
            </TableCell>
            <TableCell>
                <Price pkg={pkg} />
            </TableCell>
            <TableCell>
                <Badge variant={pkg.status === 'published' ? 'default' : 'outline'}>
                    {statusLabel(pkg.status)}
                </Badge>
            </TableCell>
            <TableCell>
                {pkg.status === 'published'
                    ? pkg.is_listed
                        ? 'Listed'
                        : 'Unlisted'
                    : '—'}
            </TableCell>
            <TableCell className="tabular-nums">
                <VersionText pkg={pkg} />
            </TableCell>
            <TableCell>
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href={pkg.edit_url}
                        aria-label={`Edit ${name}`}
                        className={cn(
                            buttonVariants({ variant: 'outline', size: 'sm' }),
                            'min-h-11 md:min-h-0',
                        )}
                    >
                        Edit
                    </Link>
                    {pkg.public_url !== null && (
                        <CopyLinkButton url={pkg.public_url} subject={name} />
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

export default function PackagesIndex({
    packages,
    new_package_url: newPackageUrl,
    catalog_url: catalogUrl,
    stripe_connected: stripeConnected,
}: PackagesIndexProps) {
    return (
        <WorkspaceShell activeSection="clients">
            <Head title="Packages" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
                data-appearance-bridge
            >
                <ClientsTabs active="packages" />
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="grid min-w-0 grid-cols-1 gap-1">
                        <h1 className="text-[17px] font-semibold tracking-tight md:text-lg">
                            Packages
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Fixed bundles clients can request from a link or add
                            from their portal.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {catalogUrl !== null && (
                            <CopyLinkButton
                                url={catalogUrl}
                                subject="the public catalog"
                                size="default"
                            />
                        )}
                        <Link
                            href={newPackageUrl}
                            className={cn(
                                buttonVariants(),
                                'min-h-11 md:min-h-0',
                            )}
                        >
                            <PlusIcon aria-hidden />
                            New package
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {packages.length === 1
                                ? '1 package'
                                : `${packages.length} packages`}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {packages.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No packages yet. Create one to offer a fixed
                                bundle of services at a fixed price.
                            </p>
                        ) : (
                            <Table scrollLabel="Packages">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="min-w-56">
                                            Package
                                        </TableHead>
                                        <TableHead>Price</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Catalog</TableHead>
                                        <TableHead>Version</TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {packages.map((pkg) => (
                                        <PackageRow key={pkg.id} pkg={pkg} />
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Public pages and payments</CardTitle>
                        <CardDescription>
                            Only published packages marked Listed appear in the
                            catalog; others are reachable by their own link.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 text-sm">
                        <div className="grid grid-cols-1 gap-1">
                            <span className="font-medium">Catalog</span>
                            {catalogUrl === null ? (
                                <span className="text-muted-foreground">
                                    The catalog link is created when you publish
                                    your first package.
                                </span>
                            ) : (
                                <span className="wrap-anywhere text-muted-foreground">
                                    {catalogUrl}
                                </span>
                            )}
                        </div>
                        <div className="grid grid-cols-1 gap-1">
                            <span className="font-medium">Stripe</span>
                            <span className="text-muted-foreground">
                                {stripeConnected
                                    ? 'Connected. Needed for setup fees, payment up front and autopay.'
                                    : 'Not connected. Needed to publish a package that takes a setup fee, payment up front or autopay.'}
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
