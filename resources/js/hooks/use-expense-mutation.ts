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
};

/** Keep one financial request intact until its outcome and refreshed rows are known. */
export function useExpenseMutation(
    only: string[],
    onLockedChange?: (locked: boolean) => void,
) {
    const attempt = useRef<Attempt | null>(null);
    const running = useRef(false);
    const [busy, setBusy] = useState(false);
    const [locked, setLocked] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        const stopNavigation = router.on?.('before', (event) => {
            const visit = event.detail.visit;
            const destination = new URL(visit.url, window.location.href);
            const refreshing =
                visit.only.length === only.length &&
                only.every((key) => visit.only.includes(key)) &&
                destination.origin === window.location.origin &&
                destination.pathname === window.location.pathname &&
                destination.search === window.location.search;

            if (
                attempt.current !== null &&
                !attempt.current.saved &&
                !refreshing
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
    }, [only]);

    const release = () => {
        attempt.current = null;
        setLocked(false);
        setSaved(false);
        onLockedChange?.(false);
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
        };
        const current = attempt.current;
        running.current = true;
        setBusy(true);
        setLocked(true);
        onLockedChange?.(true);
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
                            await refreshPageProps({
                                only,
                                requireSuccess: true,
                            });
                        }
                    }

                    return;
                }

                current.saved = true;
                setSaved(true);
            }

            await refreshPageProps({ only, requireSuccess: true });
            release();
            onDone?.();
        } catch {
            // An exception cannot establish that an in-flight request did not commit.
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

    return {
        submit,
        busy,
        locked,
        saved,
        error,
        isLocked: () => attempt.current !== null,
    };
}
