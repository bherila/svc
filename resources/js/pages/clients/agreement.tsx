import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AgreementFileUpload,
    AgreementTermsForm,
    AgreementTitle,
    formatBytes,
} from '@/components/agreements/agreement-editor';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { formatDay, formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { formatHours } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    AgreementActions,
    AgreementFile,
    AgreementTerms,
} from '@/types/agreement';

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
    company,
    home_href: homeHref,
    audience,
    actions,
    files,
    agreement,
    recurring_items: recurringItems,
}: {
    company: { id: string; name: string };
    /** Where "back" goes, since the two route families differ. */
    home_href: string;
    audience: 'operator' | 'client';
    actions: AgreementActions;
    files: AgreementFile[];
    agreement: AgreementTerms;
    recurring_items: RecurringItem[];
}) {
    const [editingTerms, setEditingTerms] = useState(false);
    // Removing a file is asked about rather than done, because it is not
    // undoable from this screen: the object is scheduled for deletion and the
    // row leaves the list, and a mis-click on a countersigned agreement is not
    // a mistake anyone here can put back.
    const [removing, setRemoving] = useState<AgreementFile | null>(null);
    // The commercial terms - term, cadence, rate, retainer, rollover - are the
    // client's own agreement, and the portal already showed them. The rest
    // describe how the billing engine behaves when a term is unstated, which is
    // an operator's concern and reads, to a client, as a list of ways their
    // invoice might vary.
    const forOperator = audience === 'operator';

    return (
        <WorkspaceShell activeModule="home">
            <Head title={agreement.title} />
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
                        <AgreementTitle
                            agreement={agreement}
                            updateHref={actions.update}
                        />
                        <Badge variant="outline">
                            {statusLabel(agreement.status)}
                        </Badge>
                        {!agreement.is_recurring && (
                            <Badge variant="outline">One time</Badge>
                        )}
                    </div>
                </header>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-3">
                        <CardTitle>Terms</CardTitle>
                        {actions.update !== null && !editingTerms && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setEditingTerms(true)}
                            >
                                Edit terms
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {actions.update !== null && editingTerms ? (
                            <AgreementTermsForm
                                agreement={agreement}
                                updateHref={actions.update}
                                onDone={() => setEditingTerms(false)}
                            />
                        ) : (
                            <dl className="grid gap-2 text-sm sm:grid-cols-2">
                                <Term
                                    label="Term"
                                    value={`${formatDay(agreement.starts_on)} → ${agreement.ends_on === null ? 'open' : formatDay(agreement.ends_on)}`}
                                />
                                <Term
                                    label="Cadence"
                                    value={
                                        agreement.billing_cadence === null
                                            ? null
                                            : statusLabel(
                                                  agreement.billing_cadence,
                                              )
                                    }
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
                                {forOperator && (
                                    <>
                                        <Term
                                            label="Rollover policy"
                                            value={
                                                agreement.rollover_policy ===
                                                null
                                                    ? null
                                                    : statusLabel(
                                                          agreement.rollover_policy,
                                                      )
                                            }
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
                                            value={
                                                agreement.first_cycle_proration ===
                                                null
                                                    ? null
                                                    : statusLabel(
                                                          agreement.first_cycle_proration,
                                                      )
                                            }
                                            unset="Prorates the opening month"
                                        />
                                        <Term
                                            label="Interim overage"
                                            value={
                                                agreement.bill_overage_interim ===
                                                null
                                                    ? null
                                                    : agreement.bill_overage_interim
                                                      ? 'Billed mid-cycle'
                                                      : 'Not billed mid-cycle'
                                            }
                                            unset="Unset — not billed mid-cycle"
                                        />
                                        <Term
                                            label="Activated"
                                            value={
                                                agreement.activated_at === null
                                                    ? null
                                                    : formatTimestamp(
                                                          agreement.activated_at,
                                                      )
                                            }
                                            unset="Never activated"
                                        />
                                        <Term
                                            label="Terminated"
                                            value={
                                                agreement.terminated_at === null
                                                    ? null
                                                    : formatTimestamp(
                                                          agreement.terminated_at,
                                                      )
                                            }
                                            unset="Not terminated"
                                        />
                                    </>
                                )}
                                <Term
                                    label="Signed"
                                    value={
                                        agreement.signed_at === null
                                            ? null
                                            : `${formatTimestamp(agreement.signed_at)}${agreement.signer_name === null ? '' : ` by ${agreement.signer_name}`}`
                                    }
                                    unset="Unsigned"
                                />
                                <Term
                                    label="Project"
                                    value={agreement.project}
                                    unset="Company-wide"
                                />
                            </dl>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Files</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4">
                        {files.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing is attached to this agreement.
                            </p>
                        ) : (
                            <ul className="grid grid-cols-1 gap-2 text-sm">
                                {files.map((file) => (
                                    <li
                                        key={file.id}
                                        className="flex flex-wrap items-center gap-x-3 gap-y-1"
                                    >
                                        <a
                                            href={file.download_href}
                                            className="font-medium wrap-anywhere underline underline-offset-4"
                                        >
                                            {file.filename}
                                        </a>
                                        <span className="text-muted-foreground">
                                            {formatBytes(file.bytes)}
                                            {file.uploaded_at !== null &&
                                                ` · added ${formatTimestamp(file.uploaded_at)}`}
                                        </span>
                                        {file.delete_href !== null && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setRemoving(file)
                                                }
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {actions.upload_file !== null && (
                            <AgreementFileUpload
                                uploadHref={actions.upload_file}
                            />
                        )}
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
                                                    {statusLabel(item.cadence)}
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
                                                    {formatDay(
                                                        item.effective_on,
                                                    )}
                                                    {item.expires_on !== null &&
                                                        ` → ${formatDay(item.expires_on)}`}
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

            <AlertDialog
                open={removing !== null}
                onOpenChange={(open: boolean) => {
                    if (!open) {
                        setRemoving(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove this file?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {removing?.filename} is scheduled for deletion and
                            stops being downloadable. This screen cannot put it
                            back.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            render={<Button variant="outline">Cancel</Button>}
                        />
                        <AlertDialogAction
                            render={
                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        const href = removing?.delete_href;
                                        setRemoving(null);

                                        if (
                                            href !== null &&
                                            href !== undefined
                                        ) {
                                            router.delete(href, {
                                                preserveScroll: true,
                                            });
                                        }
                                    }}
                                >
                                    Remove
                                </Button>
                            }
                        />
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </WorkspaceShell>
    );
}
