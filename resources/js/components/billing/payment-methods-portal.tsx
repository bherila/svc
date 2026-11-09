import { router } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useSetupIntentReturn } from '@/hooks/use-setup-intent-return';
import type { SetupConfirmation } from '@/hooks/use-setup-intent-return';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { loadStripe } from '@/lib/stripe-js';
import type { StripeElements, StripeInstance } from '@/lib/stripe-js';

/** One saved method, with the finished URLs the viewer may use on it (null when they may not). */
export type SavedMethod = {
    id: string;
    label: string;
    type: string;
    exp_month: number | null;
    exp_year: number | null;
    is_default: boolean;
    default_href: string | null;
    remove_href: string | null;
    /**
     * The server's warning to show before removing this method, when a
     * service requires autopay and removing it turns autopay off (EDGE-2);
     * null when there is nothing to warn about.
     */
    remove_warning?: string | null;
};

/** Where the add form talks to the server, and the key Stripe's form is mounted with. Null when the viewer may not add. */
export type MethodSetup = {
    setup_href: string;
    confirm_href: string;
    publishable_key: string;
};

type Confirmation = SetupConfirmation;

const RESULTS: Record<string, string> = {
    default: 'Default payment method changed.',
    removed: 'Payment method removed.',
};

/**
 * The client's saved payment methods: add one through Stripe's own form, choose
 * the default, remove one.
 *
 * Nothing here decides whether the viewer may. The server sends the URLs, or
 * null, and a sentence for the reader when there are none; a card or account
 * number is typed into Stripe's form and never reaches SVC.
 */
export function PaymentMethodsCard({
    providerName,
    companyName,
    methods,
    setup,
    notice,
    autopayEnabled,
    result,
}: {
    providerName: string;
    companyName: string;
    methods: SavedMethod[];
    setup: MethodSetup | null;
    notice: string | null;
    autopayEnabled: boolean;
    result?: string | null;
}) {
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<SavedMethod | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Stripe sends a client back here after a bank or 3-D Secure redirect.
    const returned = useSetupIntentReturn(setup?.confirm_href ?? null);

    const makeDefault = (method: SavedMethod) => {
        if (method.default_href === null || busy) {
            return;
        }

        setBusy(true);
        setError(null);
        router.post(
            method.default_href,
            {},
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'The default could not be changed.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    const remove = () => {
        if (removing?.remove_href == null || busy) {
            return;
        }

        setBusy(true);
        setError(null);
        router.delete(removing.remove_href, {
            preserveScroll: true,
            onError: (errors) =>
                setError(
                    Object.values(errors)[0] ??
                        'The payment method could not be removed.',
                ),
            onFinish: () => {
                setBusy(false);
                setRemoving(null);
            },
        });
    };

    const message =
        returned?.message ??
        (result == null ? null : (RESULTS[result] ?? null));

    return (
        <Card aria-labelledby="methods-heading">
            <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid min-w-0 grid-cols-1 gap-1">
                    <CardTitle
                        id="methods-heading"
                        role="heading"
                        aria-level={2}
                    >
                        Payment methods
                    </CardTitle>
                    <p className="max-w-prose text-sm wrap-anywhere text-muted-foreground">
                        Cards and US bank accounts saved for {companyName}.
                    </p>
                </div>
                {setup !== null && (
                    <Button
                        variant="outline"
                        type="button"
                        onClick={() => setAdding(true)}
                    >
                        <PlusIcon aria-hidden="true" />
                        Add payment method
                    </Button>
                )}
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 text-sm">
                {message !== null && (
                    <p
                        role="status"
                        className="rounded-lg border border-border px-3 py-2 wrap-anywhere"
                    >
                        {message}
                    </p>
                )}
                {error !== null && (
                    <p role="alert" className="wrap-anywhere text-destructive">
                        {error}
                    </p>
                )}
                {notice !== null && (
                    <p className="text-muted-foreground">{notice}</p>
                )}

                {methods.length === 0 ? (
                    <p className="text-muted-foreground">
                        No payment method is saved.
                        {setup === null
                            ? ''
                            : ' Add a card or bank account to use it for autopay.'}
                    </p>
                ) : (
                    <ul className="grid grid-cols-1 gap-2">
                        {methods.map((method) => (
                            <li key={method.id}>
                                <Card
                                    className={
                                        method.is_default
                                            ? 'ring-foreground'
                                            : undefined
                                    }
                                >
                                    <CardContent className="flex min-w-0 flex-wrap items-center justify-between gap-3">
                                        <div className="grid min-w-0 grid-cols-1 gap-0.5">
                                            <span className="font-medium wrap-anywhere">
                                                {method.label}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {method.exp_month !== null &&
                                                method.exp_year !== null
                                                    ? `Expires ${String(method.exp_month).padStart(2, '0')}/${String(method.exp_year).slice(-2)}`
                                                    : 'No expiry'}
                                                {method.is_default &&
                                                autopayEnabled
                                                    ? ' · Used for autopay'
                                                    : ''}
                                            </span>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            {method.is_default && (
                                                <Badge variant="secondary">
                                                    Default
                                                </Badge>
                                            )}
                                            {method.default_href !== null && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={busy}
                                                    aria-label={`Make default: ${method.label}`}
                                                    onClick={() =>
                                                        makeDefault(method)
                                                    }
                                                >
                                                    Make default
                                                </Button>
                                            )}
                                            {method.remove_href !== null && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="text-destructive"
                                                    disabled={busy}
                                                    aria-label={`Remove ${method.label}`}
                                                    onClick={() =>
                                                        setRemoving(method)
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-xs text-muted-foreground">
                    Autopay always uses the default method. Removing it turns
                    autopay off until you choose another.
                </p>
            </CardContent>

            {adding && setup !== null && (
                <AddMethodDialog
                    providerName={providerName}
                    companyName={companyName}
                    setup={setup}
                    onClose={() => setAdding(false)}
                />
            )}

            <AlertDialog
                open={removing !== null}
                onOpenChange={(open: boolean) => {
                    if (!open && !busy) {
                        setRemoving(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle className="wrap-anywhere">
                            Remove {removing?.label}?
                        </AlertDialogTitle>
                        <AlertDialogDescription className="wrap-anywhere">
                            {removing === null
                                ? ''
                                : removalWarning(removing, autopayEnabled)}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Keep it
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={(event: React.MouseEvent) => {
                                event.preventDefault();
                                remove();
                            }}
                        >
                            Remove payment method
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}

/** What removing this method changes, said before it is done. */
function removalWarning(method: SavedMethod, autopayEnabled: boolean): string {
    // The server's own sentence wins: it knows a service requires autopay (EDGE-2).
    if (method.remove_warning) {
        return method.remove_warning;
    }

    if (!method.is_default) {
        return 'It will no longer be available to pay invoices. Your default method and autopay are not affected.';
    }

    return autopayEnabled
        ? 'This is your default method, so autopay will turn off. Invoices will be yours to pay by hand until you choose another default and turn autopay on again.'
        : 'This is your default method. Autopay is off; you will need a default method to turn it on.';
}

type Phase = 'consent' | 'starting' | 'ready' | 'saving' | 'done';

/**
 * Add a card or US bank account. The client ticks that the method is kept for
 * future payments, then enters it in Stripe's own form: SVC receives only the
 * outcome, read back from Stripe by the server.
 */
export function AddMethodDialog({
    providerName,
    companyName,
    setup,
    onClose,
}: {
    providerName: string;
    companyName: string;
    setup: MethodSetup;
    onClose: () => void;
}) {
    const [phase, setPhase] = useState<Phase>('consent');
    const [agreed, setAgreed] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [outcome, setOutcome] = useState<Confirmation | null>(null);
    const stripe = useRef<StripeInstance | null>(null);
    const elements = useRef<StripeElements | null>(null);
    const mount = useRef<HTMLDivElement | null>(null);
    const key = useRef<string>(newIdempotencyKey());
    const showForm = phase === 'ready' || phase === 'saving';

    useEffect(() => {
        if (!showForm || mount.current === null || elements.current === null) {
            return undefined;
        }

        const element = elements.current.create('payment');
        element.mount(mount.current);

        return () => element.destroy();
    }, [showForm]);

    const start = async () => {
        if (!agreed) {
            return;
        }

        setError(null);
        setPhase('starting');

        try {
            const factory = await loadStripe();
            const response = await apiRequest<{ client_secret: string }>(
                'POST',
                setup.setup_href,
                {},
                key.current,
            );

            if (!response.ok) {
                setError(response.message);
                setPhase('consent');

                return;
            }

            stripe.current = factory(setup.publishable_key);
            elements.current = stripe.current.elements({
                clientSecret: response.data.client_secret,
            });
            setPhase('ready');
        } catch {
            setError('The payment form could not be loaded. Try again.');
            setPhase('consent');
        }
    };

    const save = async () => {
        if (stripe.current === null || elements.current === null) {
            return;
        }

        setError(null);
        setPhase('saving');

        const result = await stripe.current.confirmSetup({
            elements: elements.current,
            confirmParams: { return_url: window.location.href },
            redirect: 'if_required',
        });

        if (result.error !== undefined || result.setupIntent === undefined) {
            setError(
                result.error?.message ?? 'The payment method was not accepted.',
            );
            setPhase('ready');

            return;
        }

        const response = await apiRequest<Confirmation>(
            'POST',
            setup.confirm_href,
            { setup_intent: result.setupIntent.id },
        );

        if (!response.ok) {
            setError(response.message);
            setPhase('ready');

            return;
        }

        setOutcome(response.data);
        setPhase('done');
        void refreshPageProps({ requireSuccess: false });
    };

    return (
        <Dialog
            open
            onOpenChange={(open: boolean) => {
                if (!open && phase !== 'saving') {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-h-[90dvh] grid-cols-1 overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add a payment method</DialogTitle>
                    <DialogDescription className="wrap-anywhere">
                        A card or US bank account for {companyName}. You enter
                        it in Stripe’s secure form; {providerName} never sees
                        the number.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-4 text-sm">
                    {phase === 'done' && outcome !== null ? (
                        <p role="status" className="wrap-anywhere">
                            {outcome.message}
                        </p>
                    ) : (
                        <>
                            {(phase === 'consent' || phase === 'starting') && (
                                <div className="flex items-start gap-3">
                                    <input
                                        id="method-agree"
                                        type="checkbox"
                                        checked={agreed}
                                        disabled={phase === 'starting'}
                                        onChange={(event) =>
                                            setAgreed(event.target.checked)
                                        }
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    <label
                                        htmlFor="method-agree"
                                        className="leading-snug wrap-anywhere"
                                    >
                                        Save this method for future payments
                                        from {providerName}. Turning on autopay
                                        is a separate choice.
                                    </label>
                                </div>
                            )}
                            {showForm && (
                                <div
                                    ref={mount}
                                    aria-label="Payment method details"
                                    className="min-w-0 wrap-anywhere"
                                />
                            )}
                        </>
                    )}
                    {error !== null && (
                        <p
                            role="alert"
                            className="wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                </div>

                <DialogFooter>
                    {phase === 'done' ? (
                        <Button type="button" onClick={onClose}>
                            Done
                        </Button>
                    ) : (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={phase === 'saving'}
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            {showForm ? (
                                <Button
                                    type="button"
                                    disabled={phase === 'saving'}
                                    onClick={() => void save()}
                                >
                                    Save payment method
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    disabled={!agreed || phase === 'starting'}
                                    onClick={() => void start()}
                                >
                                    Continue
                                </Button>
                            )}
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
