import { router } from '@inertiajs/react';

/** Resolve only after a finished server-provided destination actually loads. */
export function visitPage(destination: string): Promise<void> {
    return new Promise<void>((resolve, reject) => {
        let settled = false;
        const finish = (loaded: boolean) => {
            if (settled) {
                return;
            }

            settled = true;
            stopBefore?.();

            if (loaded) {
                resolve();
            } else {
                reject(new Error('The requested page could not load.'));
            }
        };
        // A refused global before event produces no Inertia cancel/finish
        // callback. Check after every listener has had a chance to refuse it.
        const stopBefore = router.on?.('before', (event) => {
            if (
                new URL(event.detail.visit.url, window.location.href).href ===
                new URL(destination, window.location.href).href
            ) {
                queueMicrotask(() => {
                    stopBefore?.();

                    if (event.defaultPrevented) {
                        finish(false);
                    }
                });
            }
        });

        try {
            router.visit(destination, {
                onSuccess: () => finish(true),
                onError: () => finish(false),
                onCancel: () => finish(false),
                onNetworkError: () => {
                    finish(false);

                    return false;
                },
                onHttpException: () => {
                    finish(false);

                    return false;
                },
                onFinish: () => finish(false),
            });
        } catch {
            finish(false);
        }
    });
}
