import { useState } from 'react';
import {
    AlertDialog,
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
import { apiRequest } from '@/lib/api';

/** The server's limit on a reply (DisputeSettlements::REPLY_MAX). */
export const DISPUTE_REPLY_MAX_LENGTH = 1000;

export type DisputeOutcome = 'answered' | 'written_off';

/** How the viewer may settle a row's open dispute, as the server sends it. */
export type SettleDispute = {
    url: string;
    outcomes: DisputeOutcome[];
};

/**
 * Answer a client's dispute, or write the row off (docs/ux-overhaul.md 7b″).
 *
 * The client reads the reply as written. Answering offers the row to the
 * client again and it bills on the next draft; writing off means it is never
 * billed, and is offered only when the server says this viewer may. What is
 * refused, and why, is the server's to say.
 */
export function SettleDisputeDialog({
    open,
    onOpenChange,
    subject,
    note,
    version,
    settle,
    onSettled,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** What is disputed, as one line: date, amount or hours, description. */
    subject: string;
    /** The client's note, when they left one. */
    note: string | null;
    version: string;
    settle: SettleDispute;
    onSettled: () => void;
}) {
    const [reply, setReply] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const send = async (outcome: DisputeOutcome) => {
        if (busy || reply.trim() === '') {
            return;
        }

        setBusy(true);
        const result = await apiRequest('POST', settle.url, {
            expected_version: version,
            outcome,
            reply: reply.trim(),
            confirm: true,
        });
        setBusy(false);

        if (!result.ok) {
            setError(result.message);

            return;
        }

        setError(null);
        setReply('');
        onSettled();
    };

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next: boolean) => {
                if (!busy) {
                    onOpenChange(next);
                }
            }}
        >
            <AlertDialogContent className="grid-cols-1">
                <AlertDialogHeader>
                    <AlertDialogTitle>Respond to the dispute</AlertDialogTitle>
                    <AlertDialogDescription className="wrap-anywhere">
                        {subject}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <div className="grid grid-cols-1 gap-3">
                    <div className="grid grid-cols-1 gap-1 rounded-md border border-border p-3 text-sm">
                        <span className="text-xs font-medium text-muted-foreground">
                            The client wrote
                        </span>
                        <p className="wrap-anywhere">
                            {note ?? 'No note; they disputed it without one.'}
                        </p>
                    </div>
                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="dispute-reply">Your reply</Label>
                        <Textarea
                            id="dispute-reply"
                            disabled={busy}
                            className="max-h-48 wrap-anywhere"
                            maxLength={DISPUTE_REPLY_MAX_LENGTH}
                            value={reply}
                            aria-describedby="dispute-reply-hint"
                            onChange={(event) => setReply(event.target.value)}
                        />
                        <p
                            id="dispute-reply-hint"
                            className="text-xs text-muted-foreground"
                        >
                            The client reads this as you write it. Answering
                            puts it back on their list and on the next draft. If
                            its month's invoice was already issued, it becomes
                            deferred work instead, billed from spare retainer
                            hours, a buy-down or when the agreement ends. To
                            change the hours or amount first, edit it before you
                            answer.
                            {settle.outcomes.includes('written_off') &&
                                ' Writing it off means it is never billed.'}
                        </p>
                    </div>
                </div>
                <AlertDialogFooter>
                    {error !== null && (
                        <p
                            role="alert"
                            className="wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                    <AlertDialogCancel
                        disabled={busy}
                        render={
                            <Button disabled={busy} variant="outline">
                                Cancel
                            </Button>
                        }
                    />
                    {settle.outcomes.includes('written_off') && (
                        <Button
                            variant="destructive"
                            disabled={busy || reply.trim() === ''}
                            onClick={() => void send('written_off')}
                        >
                            Write off
                        </Button>
                    )}
                    {settle.outcomes.includes('answered') && (
                        <Button
                            disabled={busy || reply.trim() === ''}
                            onClick={() => void send('answered')}
                        >
                            Send answer
                        </Button>
                    )}
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
