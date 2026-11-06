import { CreditCardIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';

/** The slice of Stripe.js this component uses. */
type StripeElement = {
    mount: (target: HTMLElement) => void;
    destroy: () => void;
};
type StripeElements = { create: (type: 'payment') => StripeElement };
type StripeInstance = {
    elements: (options: { clientSecret: string }) => StripeElements;
    confirmPayment: (options: {
        elements: StripeElements;
        confirmParams: { return_url: string };
        redirect: 'if_required';
    }) => Promise<{ error?: { message?: string } }>;
};
type StripeFactory = (publishableKey: string) => StripeInstance;

declare global {
    interface Window {
        Stripe?: StripeFactory;
    }
}

const STRIPE_JS = 'https://js.stripe.com/v3/';

/** Stripe.js is fetched when a client asks to pay, never on page load. */
function loadStripe(): Promise<StripeFactory> {
    if (window.Stripe !== undefined) {
        return Promise.resolve(window.Stripe);
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = STRIPE_JS;
        script.async = true;
        script.onload = () =>
            window.Stripe === undefined
                ? reject(new Error('Stripe did not load.'))
                : resolve(window.Stripe);
        script.onerror = () => reject(new Error('Stripe did not load.'));
        document.head.appendChild(script);
    });
}

type Phase = 'idle' | 'starting' | 'ready' | 'paying' | 'submitted';

/**
 * Pay an invoice by card through the workspace's own Stripe account.
 *
 * The server creates the payment intent through the existing
 * `StripePaymentIntentService` route (named by `intentHref`, a finished URL),
 * which also reserves the balance; Stripe's own payment form confirms it, so
 * no card detail ever touches SVC. The payment is recorded when Stripe's
 * webhook reports it, not by this page, so a submitted payment says it is
 * being confirmed and the page then reloads its figures.
 */
export function StripeCardPayment({
    intentHref,
    publishableKey,
    primary,
}: {
    intentHref: string;
    publishableKey: string;
    /** Whether this is the page's one filled button. */
    primary: boolean;
}) {
    const [phase, setPhase] = useState<Phase>('idle');
    const [error, setError] = useState<string | null>(null);
    const stripe = useRef<StripeInstance | null>(null);
    const elements = useRef<StripeElements | null>(null);
    const mount = useRef<HTMLDivElement | null>(null);
    const key = useRef<string>(newIdempotencyKey());

    useEffect(() => {
        if (
            phase !== 'ready' ||
            mount.current === null ||
            elements.current === null
        ) {
            return undefined;
        }

        const element = elements.current.create('payment');
        element.mount(mount.current);

        return () => element.destroy();
    }, [phase]);

    const start = async () => {
        setError(null);
        setPhase('starting');

        try {
            const factory = await loadStripe();
            const result = await apiRequest<{ client_secret: string | null }>(
                'POST',
                intentHref,
                { idempotency_key: key.current },
                key.current,
            );

            if (!result.ok) {
                setError(result.message);
                setPhase('idle');

                return;
            }

            if (result.data.client_secret === null) {
                // An earlier attempt with this key already made the intent.
                key.current = newIdempotencyKey();
                setError(
                    'A payment is already in progress for this invoice. Reload the page in a moment.',
                );
                setPhase('idle');

                return;
            }

            stripe.current = factory(publishableKey);
            elements.current = stripe.current.elements({
                clientSecret: result.data.client_secret,
            });
            setPhase('ready');
        } catch {
            setError('The card form could not be loaded. Try again.');
            setPhase('idle');
        }
    };

    const pay = async () => {
        if (stripe.current === null || elements.current === null) {
            return;
        }

        setError(null);
        setPhase('paying');
        const result = await stripe.current.confirmPayment({
            elements: elements.current,
            confirmParams: { return_url: window.location.href },
            redirect: 'if_required',
        });

        if (result.error !== undefined) {
            setError(result.error.message ?? 'The payment was not accepted.');
            setPhase('ready');

            return;
        }

        setPhase('submitted');
        void refreshPageProps({ requireSuccess: false });
    };

    if (phase === 'submitted') {
        return (
            <p role="status" className="text-sm">
                Payment submitted. It shows here once Stripe confirms it.
            </p>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-3">
            {(phase === 'ready' || phase === 'paying') && (
                <>
                    <div ref={mount} aria-label="Card details" />
                    <Button
                        type="button"
                        disabled={phase === 'paying'}
                        onClick={() => void pay()}
                    >
                        <CreditCardIcon aria-hidden="true" />
                        Pay now
                    </Button>
                </>
            )}
            {(phase === 'idle' || phase === 'starting') && (
                <Button
                    type="button"
                    variant={primary ? 'default' : 'outline'}
                    disabled={phase === 'starting'}
                    onClick={() => void start()}
                >
                    <CreditCardIcon aria-hidden="true" />
                    Pay by card
                </Button>
            )}
            {error !== null && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
