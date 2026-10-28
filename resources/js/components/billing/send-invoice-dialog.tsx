import { XIcon } from 'lucide-react';
import { useRef, useState } from 'react';
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
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
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
 * The dialog closes only on recorded mailer acceptance. An unresolved outcome
 * keeps its original body and key; checking it cannot hand off another email.
 */
export function SendInvoiceDialog({
    open,
    onOpenChange,
    sendHref,
    expectedVersion,
    email,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sendHref: string;
    expectedVersion: string;
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
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [unresolved, setUnresolved] = useState(false);
    const attempt = useRef<{
        href: string;
        key: string;
        body: ApiSchema<'InvoiceSendRequest'>;
    } | null>(null);
    const retryVersion = useRef<string | null>(null);

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

    const send = async () => {
        if (busy) {
            return;
        }

        if (recipients.length === 0) {
            setError('Add at least one recipient.');

            return;
        }

        setChecking(attempt.current !== null);
        const current = attempt.current ?? {
            href: sendHref,
            key: newIdempotencyKey(),
            body: {
                expected_version: retryVersion.current ?? expectedVersion,
                confirm: true,
                recipients: [...recipients],
                subject,
                message: message.trim() === '' ? null : message,
                bcc_self: bccSelf,
            },
        };
        attempt.current = current;
        setBusy(true);
        setUnresolved(true);
        const result = await apiRequest<ApiSchema<'InvoiceSendMutation'>>(
            'POST',
            current.href,
            current.body,
            current.key,
        );

        if (!result.ok) {
            // Field validation happens before registration. Every other error
            // may have lost an already-committed result: recover the same
            // receipt, with its original body, instead of starting a new send.
            if (result.status === 422 && result.errors !== undefined) {
                attempt.current = null;
                setUnresolved(false);
                setError(result.message);
            } else {
                setError(
                    `${result.message} Use Check delivery to recover this attempt's outcome before sending again.`,
                );
            }

            setBusy(false);

            return;
        }

        const delivery = result.data?.delivery;

        if (delivery?.status === 'sent') {
            attempt.current = null;
            retryVersion.current = null;
            setUnresolved(false);
            setError(null);
            setMessage('');
            onOpenChange(false);
            await refreshPageProps();
        } else if (delivery?.status === 'failed') {
            attempt.current = null;
            retryVersion.current = result.data.version;
            setUnresolved(false);
            setError(
                `${delivery.error_summary ?? 'Invoice preparation failed.'} The message was not handed off. You can try again.`,
            );
            await refreshPageProps();
        } else {
            setError(
                delivery == null
                    ? 'This earlier attempt has no recorded delivery outcome. Check the invoice delivery history before sending again.'
                    : 'Delivery is in progress or its outcome is unknown. Check delivery to read the same attempt; another message will not be sent.',
            );
        }

        setBusy(false);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!busy) {
                    onOpenChange(value);
                }
            }}
        >
            <DialogContent
                showCloseButton={!busy}
                className="max-h-[90dvh] min-w-0 grid-cols-1 grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden sm:max-w-xl"
            >
                <DialogHeader>
                    <DialogTitle>Send this invoice</DialogTitle>
                    <DialogDescription>
                        Confirm the recipients and message. If delivery is
                        unresolved, check its recorded outcome here.
                    </DialogDescription>
                </DialogHeader>

                <fieldset
                    disabled={busy || unresolved}
                    className="grid min-h-0 min-w-0 grid-cols-1 gap-4 overflow-y-auto pr-1"
                >
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
                                className="min-w-40 flex-1"
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
                            className="max-h-64 overflow-y-auto"
                            rows={5}
                            placeholder="Anything you want said above the figures."
                            value={message}
                            onChange={(event) => setMessage(event.target.value)}
                        />
                    </div>

                    <div className="flex items-center gap-3">
                        <Switch
                            id="invoice-bcc-self"
                            disabled={busy || unresolved}
                            checked={bccSelf}
                            onCheckedChange={(checked: boolean) =>
                                setBccSelf(checked)
                            }
                        />
                        <Label htmlFor="invoice-bcc-self">
                            Blind copy me ({email.self})
                        </Label>
                    </div>
                </fieldset>

                <div className="grid min-w-0 grid-cols-1 gap-3">
                    {error !== null && (
                        <p
                            role="alert"
                            className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={send}>
                            {busy
                                ? checking
                                    ? 'Checking…'
                                    : 'Sending…'
                                : unresolved
                                  ? 'Check delivery'
                                  : 'Send'}
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}
