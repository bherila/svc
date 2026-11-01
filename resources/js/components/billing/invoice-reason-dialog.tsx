import type { ReactNode } from 'react';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { useInvoiceMutation } from '@/hooks/use-invoice-mutation';

/** How long a reason may be: the server's limit for void and reopen alike. */
export const INVOICE_REASON_MAX_LENGTH = 1000;

/**
 * A lifecycle action on an invoice that needs a reason and a confirmation:
 * void, and reopen for reissue.
 *
 * The page owns the mutation, so an uncertain handoff keeps this dialog
 * mounted and non-cancellable until the original request is checked. What the
 * action refuses, and why, is the server's to say and arrives through
 * `mutation.error`; nothing here decides whether an invoice qualifies.
 */
export function InvoiceReasonDialog({
    open,
    onOpenChange,
    mutation,
    title,
    description,
    reasonId,
    reasonHint,
    reason,
    onReasonChange,
    confirmLabel,
    destructive = false,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mutation: ReturnType<typeof useInvoiceMutation>;
    title: string;
    description: ReactNode;
    reasonId: string;
    reasonHint: string;
    reason: string;
    onReasonChange: (reason: string) => void;
    confirmLabel: string;
    destructive?: boolean;
    onConfirm: (reason: string) => void;
}) {
    return (
        <AlertDialog
            open={open}
            onOpenChange={(next: boolean) => {
                if (!mutation.isLocked()) {
                    onOpenChange(next);
                }
            }}
        >
            <AlertDialogContent className="grid-cols-1">
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription className="wrap-anywhere">
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <div className="grid grid-cols-1 gap-2">
                    <Label htmlFor={reasonId}>Reason</Label>
                    <Textarea
                        disabled={mutation.locked}
                        id={reasonId}
                        className="max-h-48 wrap-anywhere"
                        maxLength={INVOICE_REASON_MAX_LENGTH}
                        value={reason}
                        aria-describedby={`${reasonId}-hint`}
                        onChange={(event) => onReasonChange(event.target.value)}
                    />
                    <p
                        id={`${reasonId}-hint`}
                        className="text-xs text-muted-foreground"
                    >
                        {reasonHint}
                    </p>
                </div>
                <AlertDialogFooter>
                    {mutation.error !== null && (
                        <p
                            role="alert"
                            className="wrap-anywhere text-destructive"
                        >
                            {mutation.error}
                        </p>
                    )}
                    {mutation.locked && (
                        <Button
                            disabled={mutation.busy}
                            onClick={() => void mutation.retry()}
                        >
                            {mutation.saved
                                ? 'Refresh saved result'
                                : 'Check original request'}
                        </Button>
                    )}
                    <AlertDialogCancel
                        disabled={mutation.locked}
                        render={
                            <Button
                                disabled={mutation.locked}
                                variant="outline"
                            >
                                Cancel
                            </Button>
                        }
                    />
                    <AlertDialogAction
                        render={
                            <Button
                                variant={
                                    destructive ? 'destructive' : 'default'
                                }
                                disabled={
                                    mutation.locked || reason.trim() === ''
                                }
                                onClick={() => onConfirm(reason.trim())}
                            >
                                {confirmLabel}
                            </Button>
                        }
                    />
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
