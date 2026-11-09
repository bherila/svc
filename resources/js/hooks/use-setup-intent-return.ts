import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';

/** What the server read back from Stripe about a saved payment method. */
export type SetupConfirmation = {
    outcome: 'saved' | 'pending' | 'failed';
    message: string;
};

/**
 * Stripe sends a client back to the page that opened the save-a-method form
 * after a bank or 3-D Secure redirect, with the SetupIntent in the address.
 * Read it back through the server's confirm endpoint - which records the
 * method, the first one as the default - then tidy the address and refresh
 * the page's props. Mount it on every page that offers that form, since its
 * return address is the page it was opened on. Null until a return is read,
 * and always while `confirmHref` is null.
 */
export function useSetupIntentReturn(
    confirmHref: string | null,
): SetupConfirmation | null {
    const [returned, setReturned] = useState<SetupConfirmation | null>(null);

    useEffect(() => {
        if (confirmHref === null) {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const intent = params.get('setup_intent');

        if (intent === null || !/^seti_[A-Za-z0-9_]+$/.test(intent)) {
            return;
        }

        const clean = new URL(window.location.href);
        [
            'setup_intent',
            'setup_intent_client_secret',
            'redirect_status',
        ].forEach((name) => clean.searchParams.delete(name));
        window.history.replaceState(null, '', clean.pathname + clean.search);
        void apiRequest<SetupConfirmation>('POST', confirmHref, {
            setup_intent: intent,
        }).then((response) => {
            setReturned(
                response.ok
                    ? response.data
                    : { outcome: 'failed', message: response.message },
            );

            if (response.ok) {
                void refreshPageProps({ requireSuccess: false });
            }
        });
    }, [confirmHref]);

    return returned;
}
