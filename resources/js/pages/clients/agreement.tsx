import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import ClientContextLayout from '@/layouts/client-context-layout';
import { formatMoney } from '@/lib/money';
import { formatHours } from '@/lib/time';
import type { CompanyAgreement } from '@/types/clients';

type AgreementTerms = CompanyAgreement & {
    hourly_rate_amount: number | null;
    rollover_policy: string | null;
    catch_up_threshold_minutes: number | null;
    first_cycle_proration: string | null;
    bill_overage_interim: boolean | null;
    activated_at: string | null;
    terminated_at: string | null;
    signer_name: string | null;
    signer_title: string | null;
};

type RecurringItem = {
    id: string;
    description: string;
    cadence: string | null;
    quantity: number | null;
    amount: number | null;
    currency: string | null;
    effective_on: string | null;
    expires_on: string | null;
    is_active: boolean;
};

/**
 * A row of the terms list, where an unstated term is not a zero.
 *
 * The distinction is the whole point of this screen: a null catch-up threshold
 * means the engine defaults to an hour, a null rate means it refuses to price
 * rather than pricing at nothing, and showing either as "0" would describe an
 * agreement nobody signed.
 */
function Term({
    label,
    value,
    unset = 'Not stated',
}: {
    label: string;
    value: string | null;
    unset?: string;
}) {
    return (
        <div className="flex gap-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={value === null ? 'text-muted-foreground' : ''}>
                {value ?? unset}
            </dd>
        </div>
    );
}

export default function ClientAgreementDetail({
    workspace,
    company,
    agreement,
    recurring_items: recurringItems,
}: {
    workspace: { id: string };
    company: { id: string; name: string };
    agreement: AgreementTerms;
    recurring_items: RecurringItem[];
}) {
    return (
        <ClientContextLayout active="overview">
            <Head title={agreement.title} />
            <main className="mx-auto grid max-w-5xl gap-6 p-6">
                <header className="grid gap-1">
                    <Link
                        href={`/workspaces/${workspace.id}/clients/${company.id}`}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← {company.name} overview
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold">
                            {agreement.title}
                        </h1>
                        <Badge variant="outline">{agreement.status}</Badge>
                        {!agreement.is_recurring && (
                            <Badge variant="outline">One time</Badge>
                        )}
                    </div>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>Terms</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-2 text-sm sm:grid-cols-2">
                            <Term
                                label="Term"
                                value={`${agreement.starts_on} → ${agreement.ends_on ?? 'open'}`}
                            />
                            <Term
                                label="Cadence"
                                value={agreement.billing_cadence}
                            />
                            <Term
                                label="Hourly rate"
                                value={
                                    agreement.hourly_rate_amount === null
                                        ? null
                                        : formatMoney(
                                              agreement.hourly_rate_amount,
                                              agreement.currency,
                                          )
                                }
                                unset="Unpriced — the rate lookup refuses"
                            />
                            <Term
                                label="Retainer per period"
                                value={
                                    agreement.retainer_minutes_per_period ===
                                    null
                                        ? null
                                        : formatHours(
                                              agreement.retainer_minutes_per_period,
                                          )
                                }
                                unset="No recurring capacity"
                            />
                            <Term
                                label="Retainer fee"
                                value={
                                    agreement.retainer_amount_per_period ===
                                    null
                                        ? null
                                        : formatMoney(
                                              agreement.retainer_amount_per_period,
                                              agreement.currency,
                                          )
                                }
                                unset="No retainer fee"
                            />
                            <Term
                                label="Rollover"
                                value={
                                    agreement.rollover_months === null
                                        ? null
                                        : `${agreement.rollover_months} months`
                                }
                                unset="Nothing carries forward"
                            />
                            <Term
                                label="Rollover policy"
                                value={agreement.rollover_policy}
                            />
                            <Term
                                label="Catch-up threshold"
                                value={
                                    agreement.catch_up_threshold_minutes ===
                                    null
                                        ? null
                                        : formatHours(
                                              agreement.catch_up_threshold_minutes,
                                          )
                                }
                                unset="Defaults to one hour, capped at the retainer"
                            />
                            <Term
                                label="First cycle"
                                value={agreement.first_cycle_proration}
                                unset="Prorates the opening month"
                            />
                            <Term
                                label="Interim overage"
                                value={
                                    agreement.bill_overage_interim === null
                                        ? null
                                        : agreement.bill_overage_interim
                                          ? 'Billed mid-cycle'
                                          : 'Not billed mid-cycle'
                                }
                                unset="Unset — not billed mid-cycle"
                            />
                            <Term
                                label="Activated"
                                value={agreement.activated_at}
                                unset="Never activated"
                            />
                            <Term
                                label="Signed"
                                value={
                                    agreement.signed_at === null
                                        ? null
                                        : `${agreement.signed_at}${agreement.signer_name === null ? '' : ` by ${agreement.signer_name}`}`
                                }
                                unset="Unsigned"
                            />
                            <Term
                                label="Terminated"
                                value={agreement.terminated_at}
                                unset="Not terminated"
                            />
                            <Term
                                label="Project"
                                value={agreement.project}
                                unset="Company-wide"
                            />
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recurring items</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recurringItems.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                This agreement generates no recurring lines.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Description</TableHead>
                                            <TableHead>Cadence</TableHead>
                                            <TableHead>Quantity</TableHead>
                                            <TableHead>Amount</TableHead>
                                            <TableHead>Effective</TableHead>
                                            <TableHead>Active</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recurringItems.map((item) => (
                                            <TableRow key={item.id}>
                                                <TableCell className="font-medium">
                                                    {item.description}
                                                </TableCell>
                                                <TableCell>
                                                    {item.cadence ?? '—'}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {item.quantity ?? '—'}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {item.amount === null
                                                        ? '—'
                                                        : formatMoney(
                                                              item.amount,
                                                              item.currency,
                                                          )}
                                                </TableCell>
                                                <TableCell>
                                                    {item.effective_on ?? '—'}
                                                    {item.expires_on !== null &&
                                                        ` → ${item.expires_on}`}
                                                </TableCell>
                                                <TableCell>
                                                    {item.is_active
                                                        ? 'Yes'
                                                        : 'No'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
        </ClientContextLayout>
    );
}
