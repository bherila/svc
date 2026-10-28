import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';

type SavedContent = { version: string; web_url: string };

/** One logical edit retains its receipt until authoritative content is loaded. */
export function useCollaborationMutation() {
    const [pending, setPending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const inFlight = useRef(false);
    const attempt = useRef<{ signature: string; key: string } | null>(null);

    async function run(
        method: 'POST' | 'PATCH' | 'DELETE',
        href: string,
        body: object,
        saved?: (content: SavedContent) => void,
        navigate = false,
    ) {
        if (inFlight.current) {
            return;
        }

        const signature = JSON.stringify([method, href, body]);

        if (attempt.current?.signature !== signature) {
            attempt.current = { signature, key: newIdempotencyKey() };
        }

        inFlight.current = true;
        setPending(true);
        setErrors({});

        try {
            const result = await apiRequest<SavedContent>(
                method,
                href,
                body,
                attempt.current.key,
            );

            if (!result.ok) {
                setErrors({
                    message: result.message,
                    ...Object.fromEntries(
                        Object.entries(result.errors ?? {}).map(
                            ([field, messages]) => [field, messages[0]],
                        ),
                    ),
                });

                if (result.status >= 400 && result.status < 500) {
                    attempt.current = null;
                }

                return;
            }

            if (navigate) {
                await new Promise<void>((resolve, reject) => {
                    let loaded = false;
                    router.visit(result.data.web_url, {
                        onSuccess: () => {
                            loaded = true;
                            resolve();
                        },
                        onFinish: () => {
                            if (!loaded) {
                                reject(new Error('Content navigation failed.'));
                            }
                        },
                    });
                });
            } else {
                await refreshPageProps({
                    only: ['topic', 'thread', 'topics'],
                    requireSuccess: true,
                });
            }

            attempt.current = null;
            saved?.(result.data);
        } catch {
            setErrors({
                message:
                    'The change was saved, but current content could not be loaded. Retry or reload before making another change.',
            });
        } finally {
            inFlight.current = false;
            setPending(false);
        }
    }

    return { pending, errors, run };
}
