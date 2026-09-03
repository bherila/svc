import { router } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { InvoiceEmailContext } from '@/types/invoice-email';

/**
 * Composing the email that carries an invoice.
 *
 * "Send to client" used to be a button with nothing behind it: one click, a
 * cheerful "Invoice delivery queued", and no way to find out where it went or
 * whether it went at all. Everything this dialog shows is something an operator
 * had to know and could not see.
 *
 * - **Who it goes to.** Suggested from the client's billing address and its
 *   portal users, and editable, because the right recipient for one invoice is
 *   not always the address on the client record.
 * - **What address it comes from.** Read-only: it is the one part of the
 *   message the sender cannot change and the recipient reads first, and an
 *   operator who does not know it cannot tell a client where to reply.
 * - **A copy to themselves.** A checkbox rather than a field. The only blind
 *   copy offered is the sender's own, and a free address here would be the one
 *   thing about a blind copy invisible to everyone.
 *
 * The send is synchronous, so the result is real: the dialog stays open on a
 * failure and shows what the mail server said, rather than closing on a promise.
 */
export function SendInvoiceDialog({
    open,
    onOpenChange,
    sendHref,
    email,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sendHref: string;
    email: InvoiceEmailContext;
}) {
    const [recipients, setRecipients] = useState<string[]>(() =>
        email.suggested_recipients.map((suggestion) => suggestion.email),
    );
    const [adding, setAdding] = useState('');
    const [subject, setSubject] = useState(email.default_subject);
    const [message, setMessage] = useState('');
    const [bccSelf, setBccSelf] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const add = () => {
        const address = adding.trim();

        if (address === '') {
            return;
        }

        setAdding('');
        setError(null);
        setRecipients((current) =>
            current.includes(address) ? current : [...current, address],
        );
    };

    const send = () => {
        if (busy) {
            return;
        }

        if (recipients.length === 0) {
            setError('Add at least one recipient.');

            return;
        }

        setBusy(true);
        router.post(
            sendHref,
            {
                recipients,
                subject,
                message: message.trim() === '' ? null : message,
                bcc_self: bccSelf,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setError(null);
                    setMessage('');
                    onOpenChange(false);
                },
                // Stays open, holding what was typed. A failed send is a thing
                // to try again after fixing an address, not a form to fill in
                // twice.
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'That message could not be sent.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>Send this invoice</DialogTitle>
                    <DialogDescription>
                        Sent now, not queued — you will be told whether it went.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-4">
                    <div className="grid grid-cols-1 gap-1 text-sm">
                        <span className="text-muted-foreground">From</span>
                        <span className="wrap-anywhere">{email.from}</span>
                    </div>

                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="invoice-recipient">To</Label>
                        {recipients.length > 0 && (
                            <ul className="flex flex-wrap gap-2">
                                {recipients.map((address) => (
                                    <li
                                        key={address}
                                        className="flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-sm"
                                    >
                                        <span className="wrap-anywhere">
                                            {address}
                                        </span>
                                        <button
                                            type="button"
                                            aria-label={`Remove ${address}`}
                                            className="rounded-full p-0.5 hover:bg-muted"
                                            onClick={() =>
                                                setRecipients((current) =>
                                                    current.filter(
                                                        (kept) =>
                                                            kept !== address,
                                                    ),
                                                )
                                            }
                                        >
                                            <XIcon
                                                aria-hidden="true"
                                                className="size-3"
                                            />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Input
                                id="invoice-recipient"
                                type="email"
                                className="max-w-xs"
                                placeholder="Add another address"
                                value={adding}
                                onChange={(event) =>
                                    setAdding(event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        // Otherwise Enter submits the dialog and
                                        // sends the invoice to whoever is on the
                                        // list, without the address being typed.
                                        event.preventDefault();
                                        add();
                                    }
                                }}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={add}
                            >
                                Add
                            </Button>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="invoice-subject">Subject</Label>
                        <Input
                            id="invoice-subject"
                            value={subject}
                            onChange={(event) => setSubject(event.target.value)}
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="invoice-message">
                            Covering note (optional)
                        </Label>
                        <Textarea
                            id="invoice-message"
                            rows={5}
                            placeholder="Anything you want said above the figures."
                            value={message}
                            onChange={(event) => setMessage(event.target.value)}
                        />
                    </div>

                    <div className="flex items-center gap-3">
                        <Switch
                            id="invoice-bcc-self"
                            checked={bccSelf}
                            onCheckedChange={(checked: boolean) =>
                                setBccSelf(checked)
                            }
                        />
                        <Label htmlFor="invoice-bcc-self">
                            Blind copy me ({email.self})
                        </Label>
                    </div>

                    {error !== null && (
                        <p
                            role="alert"
                            className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        >
                            {error}
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button disabled={busy} onClick={send}>
                        {busy ? 'Sending…' : 'Send'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
