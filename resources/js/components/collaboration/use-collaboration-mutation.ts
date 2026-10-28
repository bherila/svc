import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';

type SavedContent = { version: string; web_url: string };
type Attempt = {
    method: 'POST' | 'PATCH' | 'DELETE';
    href: string;
    body: object;
    key: string;
    uncertain: boolean;
    content?: SavedContent;
    saved?: (content: SavedContent) => void;
    navigate: boolean;
};

/** Keep the original request until its outcome and authoritative content are known. */
export function useCollaborationMutation() {
    const [pending, setPending] = useState(false);
    const [unresolved, setUnresolved] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const inFlight = useRef(false);
    const attempt = useRef<Attempt | null>(null);

    async function run(
        method: Attempt['method'],
        href: string,
        body: object,
        saved?: (content: SavedContent) => void,
        navigate = false,
    ) {
        if (inFlight.current) {
            return;
        }

        attempt.current ??= {
            method,
            href,
            body: structuredClone(body),
            key: newIdempotencyKey(),
            uncertain: false,
            saved,
            navigate,
        };
        const original = attempt.current;
        inFlight.current = true;
        setPending(true);
        setErrors({});

        try {
            // A confirmed save only needs its failed read/navigation retried.
            if (!original.content) {
                const result = await apiRequest<SavedContent>(
                    original.method,
                    original.href,
                    original.body,
                    original.key,
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
                    typeof result.data.web_url !== 'string' ||
                    result.data.web_url.trim() === ''
                ) {
                    original.uncertain = true;
                    setUnresolved(true);
                    setErrors({
                        message:
                            'SVC’s response could not be read. Check the original change.',
                    });

                    return;
                }

                original.content = result.data;
            }

            const content = original.content;

            if (original.navigate) {
                await new Promise<void>((resolve, reject) => {
                    let loaded = false;
                    router.visit(content.web_url, {
                        onNetworkError: () => false,
                        onHttpException: () => false,
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

            original.saved?.(content);
            attempt.current = null;
            setUnresolved(false);
        } catch {
            original.uncertain = true;
            setUnresolved(true);
            setErrors({
                message:
                    'The change could not be confirmed on this page. Check the original change before editing again.',
            });
        } finally {
            inFlight.current = false;
            setPending(false);
        }
    }

    return { pending, unresolved, locked: pending || unresolved, errors, run };
}
