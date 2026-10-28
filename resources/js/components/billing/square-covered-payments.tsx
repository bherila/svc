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
import { Button } from '@/components/ui/button';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { refreshPageProps } from '@/lib/refresh-page-props';

type Review = ApiSchema<'SquareCoveredPaymentReviewResponse'>['data'];
type Payment = Review['payments'][number];

/** URLs and recordability come from the current server review, never inferred from an id. */
export function SquareCoveredPayments({ href }: { href: string }) {
    const [review, setReview] = useState<Review | null>(null);
    const [selected, setSelected] = useState<{
        payment: Payment;
        key: string;
    } | null>(null);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const load = async () => {
        setBusy(true);
        setNotice(null);
        setReview(null);
        const result = await apiRequest<Review>('GET', href, {});

        if (result.ok) {
            setReview(result.data);
        } else {
            setNotice(result.message);
        }

        setBusy(false);
    };

    const record = async () => {
        if (
            busy ||
            selected === null ||
            review === null ||
            selected.payment.record_href === null
        ) {
            return;
        }

        setBusy(true);
        const result = await apiRequest(
            'POST',
            selected.payment.record_href,
            {
                expected_version: review.invoice_version,
                reviewed_version: selected.payment.reviewed_version,
                confirm: true,
            } satisfies ApiSchema<'SquareCoveredPaymentRecordRequest'>,
            selected.key,
        );

        if (result.ok) {
            setSelected(null);
            setReview(null);
            setNotice(
                'The reviewed payment was recorded. Review again before recording another.',
            );
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setNotice(result.message);

        if (
            result.status === 409 ||
            result.status === 422 ||
            result.status === 404
        ) {
            setSelected(null);
            setReview(null);
        }

        setBusy(false);
    };

    return (
        <div className="grid min-w-0 grid-cols-1 gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={busy}
                onClick={() => void load()}
            >
                {busy ? 'Checking payments…' : 'Review payments'}
            </Button>
            {notice !== null && (
                <p role="status" className="min-w-0 wrap-anywhere">
                    {notice}
                </p>
            )}
            {review !== null && (
                <div className="grid min-w-0 grid-cols-1 gap-3 rounded-lg border p-3">
                    <p className="wrap-anywhere">
                        Review payments from Square invoice{' '}
                        {review.square_invoice_number} against{' '}
                        {review.invoice_number ?? 'this invoice'}; remaining
                        balance{' '}
                        {formatMoney(review.balance_amount, review.currency)}.
                    </p>
                    <p className="text-muted-foreground">
                        Each payment is checked again before recording.
                        Refunded, disputed or over-balance payments need manual
                        review.
                    </p>
                    {review.payments.length === 0 && (
                        <p>No completed payments were found.</p>
                    )}
                    {review.payments.map((payment) => (
                        <div
                            key={payment.square_payment_id}
                            className="grid min-w-0 grid-cols-1 gap-1 border-t pt-2"
                        >
                            <p className="wrap-anywhere">
                                {formatMoney(payment.amount, payment.currency)}{' '}
                                · received {payment.received_on}
                            </p>
                            <p className="font-mono text-xs wrap-anywhere">
                                {payment.square_payment_id}
                            </p>
                            {payment.refusal !== null && (
                                <p className="wrap-anywhere text-muted-foreground">
                                    {payment.refusal}
                                </p>
                            )}
                            {payment.record_href !== null && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        setSelected({
                                            payment,
                                            key: newIdempotencyKey(),
                                        })
                                    }
                                >
                                    Record this payment
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            )}
            <AlertDialog
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) {
                        setSelected(null);
                    }
                }}
            >
                <AlertDialogContent className="min-w-0 wrap-anywhere">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Record this Square payment?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {selected !== null && review !== null && (
                                <>
                                    Record{' '}
                                    {formatMoney(
                                        selected.payment.amount,
                                        selected.payment.currency,
                                    )}{' '}
                                    received on {selected.payment.received_on}{' '}
                                    against{' '}
                                    {review.invoice_number ?? 'this invoice'}.
                                    Its remaining balance is{' '}
                                    {formatMoney(
                                        review.balance_amount,
                                        review.currency,
                                    )}
                                    . This records money already received; it
                                    does not charge the client.
                                </>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={(event) => {
                                event.preventDefault();
                                void record();
                            }}
                        >
                            Confirm recording
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
