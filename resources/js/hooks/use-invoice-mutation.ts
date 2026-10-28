import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';

type Attempt = {
    method: 'POST' | 'PATCH';
    url: string;
    body: object;
    key: string;
    uncertain: boolean;
    saved: boolean;
    onDone?: () => void;
};

/** Preserve one invoice write until its outcome and authoritative props are known. */
export function useInvoiceMutation() {
    const attempt = useRef<Attempt | null>(null);
    const running = useRef(false);
    const refreshing = useRef(false);
    const [busy, setBusy] = useState(false);
    const [locked, setLocked] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const stopNavigation = router.on?.('before', (event) => {
            const destination = new URL(
                event.detail.visit.url,
                window.location.href,
            );
            const samePage =
                destination.origin === window.location.origin &&
                destination.pathname === window.location.pathname &&
                destination.search === window.location.search;

            if (
                attempt.current !== null &&
                !attempt.current.saved &&
                !(refreshing.current && samePage)
            ) {
                event.preventDefault();
                setError('Check the original result before leaving this page.');
            }
        });
        const stopUnload = (event: BeforeUnloadEvent) => {
            if (attempt.current !== null && !attempt.current.saved) {
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', stopUnload);

        return () => {
            stopNavigation?.();
            window.removeEventListener('beforeunload', stopUnload);
        };
    }, []);

    const release = () => {
        attempt.current = null;
        setLocked(false);
        setSaved(false);
    };
    const refresh = async () => {
        refreshing.current = true;

        try {
            await refreshPageProps({ requireSuccess: true });
        } finally {
            refreshing.current = false;
        }
    };
    const run = async () => {
        const current = attempt.current;

        if (running.current || current === null) {
            return;
        }

        running.current = true;
        setBusy(true);
        setLocked(true);
        setError(null);

        try {
            if (!current.saved) {
                const result = await apiRequest(
                    current.method,
                    current.url,
                    current.body,
                    current.key,
                );

                if (!result.ok) {
                    current.uncertain ||=
                        result.status === 0 ||
                        result.status === 408 ||
                        result.status >= 500;
                    setError(result.message);

                    if (!current.uncertain) {
                        release();

                        if (result.status === 409) {
                            await refresh();
                        }
                    }

                    return;
                }

                current.saved = true;
                setSaved(true);
            }

            await refresh();
            release();
            current.onDone?.();
        } catch {
            if (attempt.current !== null) {
                current.uncertain = true;
            }

            setError(
                current.saved
                    ? 'The change was saved, but the page could not refresh. Refresh the saved result before starting another change.'
                    : 'The original result could not be checked. Check the original request before starting another change.',
            );
        } finally {
            running.current = false;
            setBusy(false);
        }
    };
    const submit = async (
        method: Attempt['method'],
        url: string,
        body: object,
        onDone?: () => void,
    ) => {
        if (running.current) {
            return;
        }

        attempt.current ??= {
            method,
            url,
            body: structuredClone(body),
            key: newIdempotencyKey(),
            uncertain: false,
            saved: false,
            onDone,
        };
        await run();
    };

    return {
        submit,
        retry: run,
        busy,
        locked,
        saved,
        error,
        isLocked: () => attempt.current !== null,
    };
}
