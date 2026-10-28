import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiResult } from '@/lib/api';

type ClientResult = Extract<ApiResult<{ version: string }>, { ok: true }>;
type Attempt = {
    method: 'POST' | 'PATCH';
    url: string;
    body: object;
    key: string;
    uncertain: boolean;
    accepted?: ClientResult;
    requireLocation: boolean;
    complete: (result: ClientResult) => void | Promise<void>;
};

/** Hold the exact write until its outcome and resulting page are confirmed. */
export function useClientChange() {
    const [busy, setBusy] = useState(false);
    const [unresolved, setUnresolved] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const inFlight = useRef(false);
    const attempt = useRef<Attempt | null>(null);

    useEffect(() => {
        if (!busy && !unresolved) {
            return;
        }

        const stopNavigation = router.on?.('before', (event) => {
            const original = attempt.current;

            if (!original || original.accepted) {
                return;
            }

            const visit = event.detail.visit;
            const destination = new URL(visit.url, window.location.href);
            const readingCurrentProps =
                visit.method === 'get' &&
                visit.only.length > 0 &&
                destination.origin === window.location.origin &&
                destination.pathname === window.location.pathname &&
                destination.search === window.location.search;

            if (!readingCurrentProps) {
                event.preventDefault();
                setError('Check the original change before leaving this page.');
            }
        });
        const stopUnload = (event: BeforeUnloadEvent) => {
            if (attempt.current !== null && !attempt.current.accepted) {
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', stopUnload);

        return () => {
            stopNavigation?.();
            window.removeEventListener('beforeunload', stopUnload);
        };
    }, [busy, unresolved]);

    async function check() {
        const original = attempt.current;

        if (!original || inFlight.current) {
            return;
        }

        inFlight.current = true;
        setBusy(true);
        setError(null);
        setErrors({});

        try {
            if (!original.accepted) {
                const result = await apiRequest<{ version: string }>(
                    original.method,
                    original.url,
                    original.body,
                    original.key,
                );

                if (!result.ok) {
                    setError(result.message);
                    setErrors(result.errors ?? {});
                    original.uncertain ||=
                        result.status === 0 ||
                        result.status === 408 ||
                        result.status >= 500;
                    setUnresolved(original.uncertain);

                    if (!original.uncertain) {
                        attempt.current = null;
                    }

                    return;
                }

                if (
                    !result.data ||
                    typeof result.data.version !== 'string' ||
                    result.data.version.trim() === '' ||
                    (original.requireLocation &&
                        (!result.location || result.location.trim() === ''))
                ) {
                    original.uncertain = true;
                    setUnresolved(true);
                    setError(
                        'SVC’s response could not be read. Check the original change.',
                    );

                    return;
                }

                original.accepted = result;
            }

            // A confirmed write retries only its read/navigation callback.
            await original.complete(original.accepted);
            attempt.current = null;
            setUnresolved(false);
        } catch {
            original.uncertain = true;
            setUnresolved(true);
            setError(
                original.accepted
                    ? 'The change was recorded, but this page could not load it. Check the original change.'
                    : 'The change could not be confirmed. Check the original change before editing again.',
            );
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }

    async function run(
        method: Attempt['method'],
        url: string,
        body: object,
        onSuccess: Attempt['complete'],
        { requireLocation = false }: { requireLocation?: boolean } = {},
    ) {
        if (inFlight.current) {
            return;
        }

        attempt.current ??= {
            method,
            url,
            body: structuredClone(body),
            key: newIdempotencyKey(),
            uncertain: false,
            complete: onSuccess,
            requireLocation,
        };
        await check();
    }

    return {
        busy,
        unresolved,
        locked: busy || unresolved,
        error,
        errors,
        run,
        check,
    };
}
