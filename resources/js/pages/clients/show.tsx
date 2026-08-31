import { Head } from '@inertiajs/react';
import { InvoiceTable } from '@/components/clients/invoice-table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ClientContextLayout from '@/layouts/client-context-layout';
import { formatMoney } from '@/lib/money';
import { formatHours } from '@/lib/time';
import type { ClientShowProps, CompanyAgreement } from '@/types/clients';

const CADENCE_LABELS: Record<string, string> = {
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    semi_annual: 'Semi-annual',
    annual: 'Annual',
    one_time: 'One time',
};

function cadenceLabel(cadence: string | null): string {
    if (cadence === null || cadence === '') {
        return 'Monthly (unset)';
    }

    return CADENCE_LABELS[cadence] ?? cadence.replace(/_/g, ' ');
}

function term(agreement: CompanyAgreement): string {
    if (agreement.starts_on === null && agreement.ends_on === null) {
        return 'No term recorded';
    }

    return `${agreement.starts_on ?? 'open'} → ${agreement.ends_on ?? 'open'}`;
}

/**
 * The retainer an agreement sells for one cycle, in the reader's terms.
 *
 * A one-time arrangement is excluded upstream rather than formatted here: its
 * retainer columns describe something bought once, and repeating them beside a
 * cadence reads as capacity granted again every period.
 */
function retainerTerms(agreement: CompanyAgreement): string | null {
    if (!agreement.is_recurring) {
        return null;
    }

    const parts: string[] = [];

    if (agreement.retainer_minutes_per_period !== null) {
        parts.push(
            `${formatHours(agreement.retainer_minutes_per_period)} per period`,
        );
    }

    if (agreement.retainer_amount_per_period !== null) {
        parts.push(
            formatMoney(
                agreement.retainer_amount_per_period,
                agreement.currency,
            ),
        );
    }

    return parts.length === 0 ? null : parts.join(' · ');
}

function AgreementCard({ agreement }: { agreement: CompanyAgreement }) {
    const retainer = retainerTerms(agreement);

    return (
        <div className="grid gap-1 rounded-xl border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="font-medium">{agreement.title}</h3>
                <Badge variant="outline">{agreement.status}</Badge>
                {agreement.project !== null && (
                    <Badge variant="secondary">{agreement.project} only</Badge>
                )}
            </div>
            <dl className="grid gap-x-6 gap-y-1 text-sm text-muted-foreground sm:grid-cols-2">
                <div className="flex gap-2">
                    <dt>Cadence</dt>
                    <dd className="text-foreground">
                        {cadenceLabel(agreement.billing_cadence)}
                        {!agreement.is_recurring && ' · not recurring'}
                    </dd>
                </div>
                <div className="flex gap-2">
                    <dt>Term</dt>
                    <dd className="text-foreground">{term(agreement)}</dd>
                </div>
                <div className="flex gap-2">
                    <dt>Retainer</dt>
                    <dd className="text-foreground">
                        {retainer ?? 'None — hourly only'}
                    </dd>
                </div>
                <div className="flex gap-2">
                    <dt>Hourly rate</dt>
                    <dd className="text-foreground">
                        {agreement.hourly_rate_amount === null
                            ? 'Not set'
                            : formatMoney(
                                  agreement.hourly_rate_amount,
                                  agreement.currency,
                              )}
                    </dd>
                </div>
                {agreement.rollover_months !== null && (
                    <div className="flex gap-2">
                        <dt>Rollover</dt>
                        <dd className="text-foreground">
                            {agreement.rollover_months} months
                        </dd>
                    </div>
                )}
            </dl>
        </div>
    );
}

export default function ClientShow({
    company,
    projects,
    agreements,
    invoice_limit: invoiceLimit,
    invoices,
}: ClientShowProps) {
    return (
        <ClientContextLayout active="overview">
            <Head title={company.name} />
            <main className="mx-auto grid max-w-6xl gap-6 p-6">
                <header className="grid gap-1">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold">
                            {company.name}
                        </h1>
                        {!company.is_active && (
                            <Badge variant="outline">Inactive</Badge>
                        )}
                    </div>
                    {company.billing_email !== null && (
                        <p className="text-sm text-muted-foreground">
                            Billing: {company.billing_email}
                        </p>
                    )}
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>Projects</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {projects.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No projects for this client.
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {projects.map((project) => (
                                    <li
                                        key={project.id}
                                        className="flex flex-wrap items-center gap-2 rounded-xl border p-3"
                                    >
                                        <span className="font-medium">
                                            {project.name}
                                        </span>
                                        <Badge variant="outline">
                                            {project.status}
                                        </Badge>
                                        {project.is_visible_to_client && (
                                            <Badge variant="secondary">
                                                Client visible
                                            </Badge>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Agreements</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {agreements.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No agreements for this client.
                            </p>
                        ) : (
                            <div className="grid gap-3">
                                {agreements.map((agreement) => (
                                    <AgreementCard
                                        key={agreement.id}
                                        agreement={agreement}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent invoices</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2">
                        <InvoiceTable
                            invoices={invoices}
                            empty="No invoices for this client."
                        />
                        {invoices.length === invoiceLimit && (
                            <p className="text-xs text-muted-foreground">
                                Showing the {invoiceLimit} most recent invoices.
                                The Invoices tab has the rest.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </main>
        </ClientContextLayout>
    );
}
