import { router } from '@inertiajs/react';
import { useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiRequest } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';

/**
 * Who collects this invoice, and its Square link, as the operator sees it.
 *
 * The state is the API's own `InvoiceSquare`; the two URLs are finished API
 * operations, null where the operator may not use them.
 */
export type InvoiceSquareState = ApiSchema<'InvoiceSquare'> & {
    link_href: string | null;
    unlink_href: string | null;
    hosted_page?: {
        url: string;
        square_invoice_number: string;
        square_status: string;
        synced_at: string | null;
    } | null;
};

/**
 * Square, on an invoice (#438): the linked Square invoice with how it was
 * matched, why SVC does not collect it, and the controls to link, re-point
 * and unlink. Nothing here talks to Square or builds a Square address.
 */
export function SquareInvoiceCard({ square }: { square: InvoiceSquareState }) {
    const link = square.link;
    const [editing, setEditing] = useState(false);
    const [unlinking, setUnlinking] = useState(false);
    const [squareInvoiceId, setSquareInvoiceId] = useState('');
    const [squareInvoiceNumber, setSquareInvoiceNumber] = useState('');
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const submit = async () => {
        if (busy || square.link_href === null) {
            return;
        }

        const body: ApiSchema<'InvoiceSquareLinkRequest'> = {
            expected_version: square.invoice_version,
            square_invoice_id: squareInvoiceId.trim(),
            square_invoice_number: squareInvoiceNumber.trim(),
            match_method: 'manual',
            // Re-pointing an invoice that is already linked is explicit.
            replace: link !== null,
        };
        setBusy(true);
        const result = await apiRequest('POST', square.link_href, body);

        if (result.ok) {
            setNotice(null);
            setEditing(false);
            router.reload({ onFinish: () => setBusy(false) });

            return;
        }

        setNotice(result.message);
        setBusy(false);
    };

    const unlink = async () => {
        if (busy || square.unlink_href === null) {
            return;
        }

        const body: ApiSchema<'InvoiceSquareUnlinkRequest'> = {
            expected_version: square.invoice_version,
            confirm: true,
        };
        setBusy(true);
        const result = await apiRequest('DELETE', square.unlink_href, body);
        setUnlinking(false);

        if (result.ok) {
            setNotice(null);
            router.reload({ onFinish: () => setBusy(false) });

            return;
        }

        setNotice(result.message);
        setBusy(false);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Square</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-3 text-sm">
                {square.square_collects && (
                    <p
                        role="status"
                        className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 wrap-anywhere"
                    >
                        Square collects this invoice. SVC does not email it to
                        the client, charge it or offer a payment link; issuing
                        it and recording payments stay open.
                    </p>
                )}

                {square.agreement_series_key !== null && (
                    <p>
                        Its agreement is billed in Square, series{' '}
                        <span className="font-mono">
                            {square.agreement_series_key}
                        </span>
                        .
                    </p>
                )}

                {link === null ? (
                    <p className="text-muted-foreground">
                        Not linked to a Square invoice.
                    </p>
                ) : (
                    <dl className="grid grid-cols-1 gap-1">
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <dt className="text-muted-foreground">
                                Square invoice
                            </dt>
                            <dd className="font-mono wrap-anywhere">
                                {link.square_invoice_number}
                            </dd>
                            <Badge variant="outline">
                                {link.match_method === 'suggested'
                                    ? `Suggested${link.score === null ? '' : `, score ${link.score}`}`
                                    : 'Matched by hand'}
                            </Badge>
                        </div>
                        <div className="flex min-w-0 flex-wrap gap-2">
                            <dt className="text-muted-foreground">Series</dt>
                            <dd className="font-mono">
                                {link.series_key ?? 'One-off'}
                            </dd>
                        </div>
                        <div className="flex min-w-0 flex-wrap gap-2">
                            <dt className="text-muted-foreground">Square id</dt>
                            <dd className="min-w-0 font-mono wrap-anywhere">
                                {link.square_invoice_id}
                            </dd>
                        </div>
                        {link.reasons !== null && link.reasons.length > 0 && (
                            <div className="flex min-w-0 flex-wrap gap-2">
                                <dt className="text-muted-foreground">
                                    Reasons
                                </dt>
                                <dd className="min-w-0 wrap-anywhere">
                                    {link.reasons.join(' · ')}
                                </dd>
                            </div>
                        )}
                        <div className="flex min-w-0 flex-wrap gap-2">
                            <dt className="text-muted-foreground">Linked</dt>
                            <dd className="min-w-0 wrap-anywhere">
                                {formatTimestamp(link.linked_at)}
                                {link.linked_by !== null &&
                                    ` by ${link.linked_by}`}
                            </dd>
                        </div>
                        {square.hosted_page != null && (
                            <div className="grid grid-cols-1 gap-1">
                                <dd>
                                    <a
                                        href={square.hosted_page.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="underline underline-offset-4"
                                    >
                                        View or pay in Square
                                    </a>
                                </dd>
                                <dt className="text-muted-foreground">
                                    {statusLabel(
                                        square.hosted_page.square_status,
                                    )}
                                    {square.hosted_page.synced_at !== null &&
                                        ` · synced ${formatTimestamp(square.hosted_page.synced_at)}`}
                                </dt>
                            </div>
                        )}
                    </dl>
                )}

                {notice !== null && (
                    <p
                        role="alert"
                        className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 wrap-anywhere text-destructive"
                    >
                        {notice}
                    </p>
                )}

                {editing && square.link_href !== null ? (
                    <form
                        className="grid grid-cols-1 gap-3 rounded-lg border border-border p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,14rem)_auto] sm:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void submit();
                        }}
                    >
                        <div className="grid grid-cols-1 gap-1.5">
                            <Label htmlFor="square-invoice-id">
                                Square invoice id
                            </Label>
                            <Input
                                id="square-invoice-id"
                                required
                                maxLength={191}
                                autoComplete="off"
                                value={squareInvoiceId}
                                onChange={(event) =>
                                    setSquareInvoiceId(event.target.value)
                                }
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-1.5">
                            <Label htmlFor="square-invoice-number">
                                Square invoice number
                            </Label>
                            <Input
                                id="square-invoice-number"
                                required
                                maxLength={191}
                                autoComplete="off"
                                placeholder="NNNNNN-R-NNNN"
                                value={squareInvoiceNumber}
                                onChange={(event) =>
                                    setSquareInvoiceNumber(event.target.value)
                                }
                            />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" size="sm" disabled={busy}>
                                {link === null ? 'Link' : 'Re-point link'}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setEditing(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                        {link !== null && (
                            <p className="text-xs text-muted-foreground sm:col-span-3">
                                Re-pointing replaces the current link; it is
                                kept in the history.
                            </p>
                        )}
                    </form>
                ) : (
                    (square.link_href !== null ||
                        square.unlink_href !== null) && (
                        <div className="flex flex-wrap gap-2">
                            {square.link_href !== null && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setSquareInvoiceId('');
                                        setSquareInvoiceNumber('');
                                        setEditing(true);
                                    }}
                                >
                                    {link === null
                                        ? 'Link to Square invoice'
                                        : 'Re-point'}
                                </Button>
                            )}
                            {square.unlink_href !== null && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => setUnlinking(true)}
                                >
                                    Unlink
                                </Button>
                            )}
                        </div>
                    )
                )}
            </CardContent>

            <AlertDialog open={unlinking} onOpenChange={setUnlinking}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Unlink from Square?</AlertDialogTitle>
                        <AlertDialogDescription>
                            The link is kept in the history.
                            {square.agreement_series_key === null
                                ? ' SVC may then email, charge and offer a payment link for this invoice again.'
                                : ' Its agreement is still billed in Square, so SVC still will not collect it.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep link</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={() => void unlink()}
                        >
                            Unlink
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}
