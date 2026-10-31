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
import { useUnknownWriteGuard } from '@/hooks/use-unknown-write-guard';
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
type ComposeContext = {
    invoiceId: string;
    href: string | null;
    version: string;
    email: InvoiceEmailContext;
};
type ComposeProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoiceId: string;
    sendHref: string | null;
    expectedVersion: string;
    email: InvoiceEmailContext | null;
};
type RefreshReason = 'conflict' | 'sent' | 'failed';
type Attempt = {
    invoiceId: string;
    href: string;
    key: string;
    body: ApiSchema<'InvoiceSendRequest'>;
    uncertain: boolean;
    refreshReason: RefreshReason | null;
    failureSummary?: string | null;
};

export function SendInvoiceDialog(props: ComposeProps) {
    // Keep an existing compose mounted when a refreshed capability disappears.
    // An uncertain original request still has to be checked with its own key.
    const [initial, setInitial] = useState<ComposeContext | null>(() =>
        props.email !== null && props.sendHref !== null
            ? {
                  invoiceId: props.invoiceId,
                  href: props.sendHref,
                  version: props.expectedVersion,
                  email: props.email,
              }
            : null,
    );

    if (initial === null && props.email !== null && props.sendHref !== null) {
        setInitial({
            invoiceId: props.invoiceId,
            href: props.sendHref,
            version: props.expectedVersion,
            email: props.email,
        });
    }

    if (initial === null) {
        return null;
    }

    return <InvoiceCompose {...props} initial={initial} />;
}

function InvoiceCompose({
    open,
    onOpenChange,
    invoiceId,
    sendHref,
    expectedVersion,
    email: incomingEmail,
    initial,
}: ComposeProps & { initial: ComposeContext }) {
    const [baseline, setBaseline] = useState(initial);
    const [wasOpen, setWasOpen] = useState(open);
    const [recipients, setRecipients] = useState<string[]>(() =>
        initial.email.suggested_recipients.map(
            (suggestion) => suggestion.email,
        ),
    );
    const [adding, setAdding] = useState('');
    const [subject, setSubject] = useState(initial.email.default_subject);
    const [message, setMessage] = useState('');
    const [bccSelf, setBccSelf] = useState(true);
    const [busy, setBusy] = useState(false);
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [unresolved, setUnresolved] = useState(false);
    const [recovery, setRecovery] = useState<RefreshReason | null>(null);
    const attempt = useRef<Attempt | null>(null);
    const running = useRef(false);

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open && !unresolved && !busy && incomingEmail !== null) {
            setBaseline({
                invoiceId,
                href: sendHref,
                version: expectedVersion,
                email: incomingEmail,
            });
        }
    }

    const email = baseline.email;
    const isUnknown = () =>
        attempt.current !== null && attempt.current.refreshReason === null;
    useUnknownWriteGuard(
        () =>
            attempt.current !== null && attempt.current.refreshReason === null,
        () => setError('Check delivery before leaving this page.'),
    );

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

    const release = () => {
        attempt.current = null;
        setUnresolved(false);
        setRecovery(null);
    };
    const send = async () => {
        if (running.current) {
            return;
        }

        if (attempt.current === null && recipients.length === 0) {
            setError('Add at least one recipient.');

            return;
        }

        if (attempt.current === null && baseline.href === null) {
            return;
        }

        setChecking(attempt.current !== null);
        let current: Attempt = attempt.current ?? {
            invoiceId: baseline.invoiceId,
            href: baseline.href ?? '',
            key: newIdempotencyKey(),
            body: {
                expected_version: baseline.version,
                confirm: true,
                recipients: [...recipients],
                subject,
                message: message.trim() === '' ? null : message,
                bcc_self: bccSelf,
            },
            uncertain: false,
            refreshReason: null,
        };
        attempt.current = current;
        running.current = true;
        setBusy(true);
        setUnresolved(true);

        try {
            if (current.refreshReason === null) {
                const result = await apiRequest<
                    ApiSchema<'InvoiceSendMutation'>
                >('POST', current.href, current.body, current.key);

                if (!result.ok) {
                    current = {
                        ...current,
                        uncertain:
                            current.uncertain ||
                            result.status === 0 ||
                            result.status === 408 ||
                            result.status >= 500,
                    };
                    attempt.current = current;

                    if (current.uncertain) {
                        setError(
                            `${result.message} Use Check delivery to recover this attempt's outcome before sending again.`,
                        );

                        return;
                    }

                    if (result.status === 409) {
                        current = { ...current, refreshReason: 'conflict' };
                        attempt.current = current;
                        setRecovery('conflict');
                    } else {
                        // A first authorization/validation refusal precedes registration.
                        release();
                        setError(result.message);

                        return;
                    }
                } else {
                    if (result.data?.id !== current.invoiceId) {
                        current = { ...current, uncertain: true };
                        attempt.current = current;
                        setError(
                            'This response could not be matched to the invoice. Use Check delivery before sending again.',
                        );

                        return;
                    }

                    const delivery = result.data.delivery;

                    if (
                        delivery?.status === 'sent' ||
                        delivery?.status === 'failed'
                    ) {
                        current = {
                            ...current,
                            refreshReason: delivery.status,
                            failureSummary: delivery.error_summary,
                        };
                        attempt.current = current;
                        setRecovery(delivery.status);
                    } else {
                        // A registered pending/sending or legacy receipt cannot be
                        // disproved by a later permission or validation refusal.
                        current = { ...current, uncertain: true };
                        attempt.current = current;
                        setError(
                            delivery == null
                                ? 'This earlier attempt has no recorded delivery outcome. Check the invoice delivery history before sending again.'
                                : 'Delivery is in progress or its outcome is unknown. Check delivery to read the same attempt; another message will not be sent.',
                        );

                        return;
                    }
                }
            }

            let observed: ComposeContext | null = null;
            await refreshPageProps({
                requireSuccess: true,
                onSuccess: (page) => {
                    const values = page.props;
                    const invoice = values.invoice as
                        { id?: string } | undefined;

                    if (
                        invoice?.id !== current.invoiceId ||
                        typeof values.version !== 'string'
                    ) {
                        return;
                    }

                    const actions = values.actions as
                        { send?: string | null } | undefined;
                    observed = {
                        invoiceId: current.invoiceId,
                        version: values.version,
                        href:
                            typeof actions?.send === 'string'
                                ? actions.send
                                : null,
                        email:
                            (values.email as
                                InvoiceEmailContext | null | undefined) ??
                            baseline.email,
                    };
                },
            });

            if (observed === null) {
                throw new Error('The current invoice could not be verified.');
            }

            setBaseline(observed);
            const reason = current.refreshReason;
            release();

            if (reason === 'sent') {
                setError(null);
                setMessage('');
                onOpenChange(false);
            } else if (reason === 'failed') {
                setError(
                    `${current.failureSummary ?? 'Invoice preparation failed.'} The message was not handed off. You can try again.`,
                );
            } else {
                setError(
                    'The invoice changed. Review its current details, then confirm Send again.',
                );
            }
        } catch {
            if (current.refreshReason === null) {
                current = { ...current, uncertain: true };
                attempt.current = current;
                setError(
                    'The delivery outcome could not be checked. Use Check delivery before sending again.',
                );
            } else {
                setError(
                    current.refreshReason === 'sent'
                        ? 'The message was sent, but the invoice could not refresh. Refresh the invoice before starting another send.'
                        : current.refreshReason === 'failed'
                          ? 'The message was not handed off, but the invoice could not refresh. Refresh the invoice before trying again.'
                          : 'The request was refused because the invoice changed. Refresh the invoice, review its current details, then confirm a new send.',
                );
            }
        } finally {
            running.current = false;
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!running.current && !isUnknown()) {
                    onOpenChange(value);
                }
            }}
        >
            <DialogContent
                showCloseButton={!busy && !(unresolved && recovery === null)}
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
                            disabled={busy || (unresolved && recovery === null)}
                            onClick={() => {
                                if (!running.current && !isUnknown()) {
                                    onOpenChange(false);
                                }
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                busy || (!unresolved && baseline.href === null)
                            }
                            onClick={send}
                        >
                            {busy && recovery !== null
                                ? 'Refreshing…'
                                : busy
                                  ? checking
                                      ? 'Checking…'
                                      : 'Sending…'
                                  : recovery !== null
                                    ? 'Refresh invoice'
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
