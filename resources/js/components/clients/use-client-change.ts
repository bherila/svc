import { useRef, useState } from 'react';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiResult } from '@/lib/api';

/** Preserve one logical edit across an uncertain network response. */
export function useClientChange() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const inFlight = useRef(false);
    const attempt = useRef<{ body: string; key: string } | null>(null);

    async function run<T>(
        method: 'POST' | 'PATCH',
        url: string,
        body: object,
        onSuccess: (
            result: Extract<ApiResult<T>, { ok: true }>,
        ) => void | Promise<void>,
    ) {
        if (inFlight.current) {
            return;
        }

        const signature = JSON.stringify([method, url, body]);

        if (attempt.current?.body !== signature) {
            attempt.current = { body: signature, key: newIdempotencyKey() };
        }

        inFlight.current = true;
        setBusy(true);
        setError(null);
        setErrors({});

        try {
            const result = await apiRequest<T>(
                method,
                url,
                body,
                attempt.current.key,
            );

            if (!result.ok) {
                setError(result.message);
                setErrors(result.errors ?? {});

                // A definite refusal ends the attempt. An uncertain network or
                // server failure retains the key so a retry cannot duplicate it.
                if (result.status >= 400 && result.status < 500) {
                    attempt.current = null;
                }

                return;
            }

            await onSuccess(result);
            attempt.current = null;
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }

    return { busy, error, errors, run };
}
