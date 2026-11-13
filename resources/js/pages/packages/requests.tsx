import { Head } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';
import { useState } from 'react';
import { ClientsTabs } from '@/components/packages/clients-tabs';
import { PageLinks } from '@/components/packages/page-links';
import { ConfirmedAction } from '@/components/shared-projects/participation-panels';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { OptionSelect } from '@/components/ui/option-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest } from '@/lib/api';
import { formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { priceLine } from '@/lib/packages';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { cn } from '@/lib/utils';
import type { PackageRequestRow, PackageRequestsProps } from '@/types/packages';

const STALE =
    'This request changed since you opened it. The page now shows its current state.';
const ERASED = 'Erased request';

/** Open requests read as the headline; a decided one is quieter. */
function statusVariant(status: string) {
    return status === 'new'
        ? 'default'
        : status === 'declined'
          ? 'secondary'
          : 'outline';
}

/** The words for a request in a list: what the visitor typed, or that it was erased. */
function companyOf(request: PackageRequestRow): string {
    return request.untrusted?.company_name ?? ERASED;
}

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-1 gap-0.5 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:gap-4">
            <dt className="min-w-0 text-sm wrap-anywhere text-muted-foreground">
                {label}
            </dt>
            <dd className="min-w-0 text-sm wrap-anywhere whitespace-pre-line">
                {children}
            </dd>
        </div>
    );
}

const dash = <span className="text-muted-foreground">—</span>;

/** What approving does, in the words of this request's own props. */
function ApprovalSummary({
    request,
    attachTo,
}: {
    request: PackageRequestRow;
    attachTo: { id: string; name: string } | null;
}) {
    const untrusted = request.untrusted;

    if (untrusted === null) {
        return null;
    }

    return (
        <div className="grid grid-cols-1 gap-2">
            <h3 className="text-sm font-medium">Approving will</h3>
            <ul className="grid list-disc grid-cols-1 gap-1 ps-5 text-sm text-muted-foreground">
                <li className="wrap-anywhere">
                    {attachTo === null ? (
                        <>
                            Create the client{' '}
                            <strong className="font-semibold text-foreground">
                                {untrusted.company_name}
                            </strong>
                        </>
                    ) : (
                        <>
                            Use the existing client{' '}
                            <strong className="font-semibold text-foreground">
                                {attachTo.name}
                            </strong>
                        </>
                    )}{' '}
                    and give it a project for{' '}
                    <strong className="font-semibold text-foreground">
                        {request.package.name}
                    </strong>
                </li>
                <li className="wrap-anywhere">
                    Give {untrusted.email} company-wide access to the portal,
                    inviting them by email if they have no account yet
                </li>
                <li className="wrap-anywhere">
                    Offer {request.package.name} for acceptance on its current
                    published terms
                </li>
            </ul>
            <NewerTerms offered={request.offered_version} />
        </div>
    );
}

/**
 * The visitor read an older version than the one an approval offers now
 * (EDGE-7): what is offered instead, so the owner approves knowing it.
 */
function NewerTerms({
    offered,
}: {
    offered: PackageRequestRow['offered_version'];
}) {
    if (offered === null || !offered.newer_than_read) {
        return null;
    }

    return (
        <p role="note" className="text-sm font-medium wrap-anywhere">
            The visitor read an earlier version. The offer will use version{' '}
            {offered.number},{' '}
            <span className="font-medium">{offered.name}</span>:{' '}
            {[
                offered.cycle_amount > 0
                    ? priceLine(
                          offered.cycle_amount,
                          offered.currency,
                          offered.billing_cadence,
                      )
                    : 'priced as described on its page',
                offered.setup_fee_amount === null
                    ? null
                    : `setup ${formatMoney(offered.setup_fee_amount, offered.currency)}`,
            ]
                .filter((part) => part !== null)
                .join(' · ')}
            .
        </p>
    );
}

function Panel({
    request,
    clients,
}: {
    request: PackageRequestRow;
    clients: PackageRequestsProps['clients'];
}) {
    const { urls, untrusted } = request;
    const [approving, setApproving] = useState(false);
    const [declining, setDeclining] = useState(false);
    const [erasing, setErasing] = useState(false);
    const [clientId, setClientId] = useState(
        request.suggested_client?.id ?? '',
    );
    const [reason, setReason] = useState('');
    // The new client's name starts as the visitor typed it; once approved it is
    // the workspace's own record, so it can be corrected here first.
    const [companyName, setCompanyName] = useState(
        untrusted?.company_name ?? '',
    );
    const attachTo = clients.find((client) => client.id === clientId) ?? null;

    // Every decision goes to the server's own URL, confirmed, against the
    // version the page showed (ADM-1).
    const decide =
        (url: string | null, extra: Record<string, string | undefined>) =>
        async () => {
            if (url === null) {
                return 'This action is not available.';
            }

            const result = await apiRequest('POST', url, {
                expected_version: request.version,
                confirm: true,
                ...Object.fromEntries(
                    Object.entries(extra).filter(
                        ([, value]) => value !== undefined,
                    ),
                ),
            });

            if (!result.ok) {
                if (result.status === 409) {
                    await refreshPageProps();

                    return STALE;
                }

                return result.message;
            }

            await refreshPageProps();

            return null;
        };

    const options = [
        {
            value: '',
            label: `Create new: ${untrusted?.company_name ?? ''}`,
        },
        ...clients.map((client) => ({
            value: client.id,
            label: `${client.name} (existing)`,
        })),
    ];

    return (
        <Card aria-label={`Request from ${companyOf(request)}`}>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <CardTitle className="min-w-0 wrap-anywhere">
                        {companyOf(request)}
                    </CardTitle>
                    <Badge variant={statusVariant(request.status)}>
                        {statusLabel(request.status)}
                    </Badge>
                </div>
                <p className="text-xs wrap-anywhere text-muted-foreground">
                    {request.package.name} · v{request.package.version_number} ·
                    received {formatTimestamp(request.submitted_at)}
                </p>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4">
                {untrusted === null ? (
                    <p className="text-sm text-muted-foreground">
                        {request.erased_at === null
                            ? 'The details of this request are not available.'
                            : `The visitor’s details were erased on ${formatTimestamp(request.erased_at)}. The package, dates and outcome remain.`}
                    </p>
                ) : (
                    <dl className="grid grid-cols-1 divide-y divide-border">
                        <Fact label="Contact">{untrusted.contact_name}</Fact>
                        <Fact label="Email">{untrusted.email}</Fact>
                        <Fact label="Phone">
                            {untrusted.phone === null || untrusted.phone === ''
                                ? dash
                                : untrusted.phone}
                        </Fact>
                        {untrusted.answers.map((answer) => (
                            <Fact key={answer.key} label={answer.question}>
                                {answer.answer === null || answer.answer === ''
                                    ? dash
                                    : answer.answer}
                            </Fact>
                        ))}
                    </dl>
                )}

                {request.status !== 'new' && (
                    <div className="grid grid-cols-1 gap-1 border-t border-border pt-4 text-sm">
                        <p className="wrap-anywhere">
                            {statusLabel(request.status)}{' '}
                            {formatTimestamp(request.decided_at)}
                            {request.client !== null &&
                                request.status === 'approved' && (
                                    <>
                                        {' '}
                                        · client{' '}
                                        <span className="font-medium">
                                            {request.client.name}
                                        </span>
                                    </>
                                )}
                        </p>
                        {request.decline_reason !== null && (
                            <p className="wrap-anywhere text-muted-foreground">
                                Reason given: {request.decline_reason}
                            </p>
                        )}
                    </div>
                )}

                {urls.approve !== null && (
                    <>
                        <div className="border-t border-border pt-4">
                            <ApprovalSummary
                                request={request}
                                attachTo={attachTo}
                            />
                        </div>
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            <Button
                                variant="outline"
                                className="min-h-11 md:min-h-0"
                                onClick={() => setDeclining(true)}
                            >
                                Decline…
                            </Button>
                            <Button
                                className="min-h-11 md:min-h-0"
                                onClick={() => setApproving(true)}
                            >
                                <CheckIcon aria-hidden />
                                Approve and invite…
                            </Button>
                        </div>
                    </>
                )}
                {urls.erase !== null && (
                    <div className="flex justify-end border-t border-border pt-4">
                        <Button
                            variant="outline"
                            className="min-h-11 md:min-h-0"
                            onClick={() => setErasing(true)}
                        >
                            Erase details…
                        </Button>
                    </div>
                )}
            </CardContent>

            {urls.approve !== null && untrusted !== null && (
                <ConfirmedAction
                    open={approving}
                    onOpenChange={setApproving}
                    title={`Approve ${untrusted.company_name}?`}
                    description={`This creates the client and project, offers the package and emails the invitation.${request.offered_version?.newer_than_read ? ` The offer uses version ${request.offered_version.number}, newer than the one the visitor read.` : ''} Choose an existing client to attach the request to it instead of creating one.`}
                    confirmLabel="Approve and invite"
                    run={decide(urls.approve, {
                        // Exactly the terms shown above (EDGE-7).
                        expected_offer_version: request.offered_version?.id,
                        client_id: clientId === '' ? undefined : clientId,
                        company_name:
                            clientId === '' && companyName.trim() !== ''
                                ? companyName.trim()
                                : undefined,
                    })}
                >
                    <div className="grid grid-cols-1 gap-1.5">
                        <Label htmlFor={`client-${request.id}`}>Client</Label>
                        <OptionSelect
                            id={`client-${request.id}`}
                            value={clientId}
                            options={options}
                            onValueChange={setClientId}
                        />
                    </div>
                    {clientId === '' && (
                        <div className="grid grid-cols-1 gap-1.5">
                            <Label htmlFor={`company-${request.id}`}>
                                New client's name
                            </Label>
                            <Input
                                id={`company-${request.id}`}
                                maxLength={200}
                                value={companyName}
                                onChange={(event) =>
                                    setCompanyName(event.target.value)
                                }
                            />
                        </div>
                    )}
                    <ApprovalSummary request={request} attachTo={attachTo} />
                </ConfirmedAction>
            )}
            {urls.decline !== null && (
                <ConfirmedAction
                    open={declining}
                    onOpenChange={setDeclining}
                    title={`Decline ${companyOf(request)}?`}
                    description="The visitor is emailed that the request was declined, with your reason if you give one. Nothing is created."
                    confirmLabel="Decline"
                    run={decide(urls.decline, {
                        reason:
                            reason.trim() === '' ? undefined : reason.trim(),
                    })}
                >
                    <div className="grid grid-cols-1 gap-1.5">
                        <Label htmlFor={`reason-${request.id}`}>
                            Reason (optional)
                        </Label>
                        <Textarea
                            id={`reason-${request.id}`}
                            rows={3}
                            maxLength={1000}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                        />
                    </div>
                </ConfirmedAction>
            )}
            {urls.erase !== null && (
                <ConfirmedAction
                    open={erasing}
                    onOpenChange={setErasing}
                    title={`Erase the details of ${companyOf(request)}?`}
                    description="The name, email, phone and answers are removed for good. The package, dates and outcome stay."
                    confirmLabel="Erase"
                    run={decide(urls.erase, {})}
                />
            )}
        </Card>
    );
}

export default function PackageRequests({
    requests,
    clients,
    next_page_url: nextPageUrl,
    first_page_url: firstPageUrl,
}: PackageRequestsProps) {
    const [chosen, setChosen] = useState<string | null>(null);
    // The first open request is the one to look at; a reload that removed the
    // chosen one falls back to it.
    const selected =
        requests.find((request) => request.id === chosen) ??
        requests.find((request) => request.status === 'new') ??
        requests[0] ??
        null;

    return (
        <WorkspaceShell activeSection="clients">
            <Head title="Sign-up requests" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
                data-appearance-bridge
            >
                <ClientsTabs active="requests" />
                <div className="grid min-w-0 grid-cols-1 gap-1">
                    <h1 className="text-[17px] font-semibold tracking-tight md:text-lg">
                        Sign-up requests
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        What visitors typed into a package’s request form.
                        Nothing is created until you approve it.
                    </p>
                </div>

                {requests.length === 0 ? (
                    <Card>
                        <CardContent className="text-sm text-muted-foreground">
                            No requests yet. They appear here when someone sends
                            the form on a package’s public page.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,32rem)]">
                        <Card aria-label="Requests">
                            <CardContent>
                                <Table
                                    scrollLabel="Requests"
                                    className="min-w-[28rem]"
                                >
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="min-w-56">
                                                From
                                            </TableHead>
                                            <TableHead>Received</TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {requests.map((request) => (
                                            <TableRow
                                                key={request.id}
                                                data-state={
                                                    request.id === selected?.id
                                                        ? 'selected'
                                                        : undefined
                                                }
                                                className={cn(
                                                    request.id ===
                                                        selected?.id &&
                                                        'bg-muted/50',
                                                )}
                                            >
                                                <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                    <button
                                                        type="button"
                                                        aria-current={
                                                            request.id ===
                                                            selected?.id
                                                                ? 'true'
                                                                : undefined
                                                        }
                                                        onClick={() =>
                                                            setChosen(
                                                                request.id,
                                                            )
                                                        }
                                                        className="min-h-11 max-w-full text-start font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:min-h-0"
                                                    >
                                                        {companyOf(request)}
                                                    </button>
                                                    {request.untrusted !==
                                                        null && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {request.suggested_client !==
                                                                null &&
                                                            request.status ===
                                                                'new'
                                                                ? `existing client · ${request.untrusted.contact_name}`
                                                                : `${request.untrusted.contact_name} · ${request.untrusted.email}`}
                                                        </p>
                                                    )}
                                                    <p className="text-xs text-muted-foreground">
                                                        {request.package.name}
                                                    </p>
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatTimestamp(
                                                        request.submitted_at,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={statusVariant(
                                                            request.status,
                                                        )}
                                                    >
                                                        {statusLabel(
                                                            request.status,
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>

                        {selected !== null && (
                            <Panel
                                key={`${selected.id}:${selected.version}`}
                                request={selected}
                                clients={clients}
                            />
                        )}
                    </div>
                )}
                <PageLinks
                    label="Request pages"
                    firstPageUrl={firstPageUrl}
                    nextPageUrl={nextPageUrl}
                />
            </main>
        </WorkspaceShell>
    );
}
