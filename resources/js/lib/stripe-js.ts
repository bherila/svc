/** The slice of Stripe.js the application uses. */
export type StripeElement = {
    mount: (target: HTMLElement) => void;
    destroy: () => void;
};
export type StripeElements = { create: (type: 'payment') => StripeElement };
export type StripeInstance = {
    elements: (options: { clientSecret: string }) => StripeElements;
    confirmPayment: (options: {
        elements: StripeElements;
        confirmParams: { return_url: string };
        redirect: 'if_required';
    }) => Promise<{ error?: { message?: string } }>;
    confirmSetup: (options: {
        elements: StripeElements;
        confirmParams: { return_url: string };
        redirect: 'if_required';
    }) => Promise<{
        error?: { message?: string };
        setupIntent?: { id: string; status: string };
    }>;
};
export type StripeFactory = (publishableKey: string) => StripeInstance;

declare global {
    interface Window {
        Stripe?: StripeFactory;
    }
}

const STRIPE_JS = 'https://js.stripe.com/v3/';

/** Stripe.js is fetched when a client asks to pay, never on page load. */
export function loadStripe(): Promise<StripeFactory> {
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
