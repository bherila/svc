import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';

type RefreshPagePropsOptions = {
    only?: string[];
    requireSuccess?: boolean;
    onFinish?: () => void;
    onSuccess?: (page: Page) => void;
};

/** Refresh authoritative props; callers retain their own mutation/retry policy. */
export function refreshPageProps(
    options: RefreshPagePropsOptions = {},
): Promise<void> {
    return reloadPage(options).then(() => undefined);
}

/**
 * Refresh authoritative props and resolve with the page Inertia returned, so a
 * caller derives what it observed from the awaited value instead of assigning
 * to variables captured by a callback. Rejects unless the refresh succeeded.
 */
export function reloadPageProps(
    options: Omit<RefreshPagePropsOptions, 'requireSuccess'> = {},
): Promise<Page> {
    return reloadPage({ ...options, requireSuccess: true }).then((page) => {
        if (page === undefined) {
            throw new Error('Current page props could not be refreshed.');
        }

        return page;
    });
}

function reloadPage({
    onFinish,
    onSuccess,
    requireSuccess = false,
    ...options
}: RefreshPagePropsOptions): Promise<Page | undefined> {
    return new Promise<Page | undefined>((resolve, reject) => {
        let succeeded = false;
        let refreshed: Page | undefined;
        let settled = false;
        const finish = () => {
            if (settled) {
                return;
            }

            settled = true;
            stopBefore?.();

            try {
                onFinish?.();
            } finally {
                if (requireSuccess && !succeeded) {
                    reject(
                        new Error('Current page props could not be refreshed.'),
                    );
                } else {
                    resolve(refreshed);
                }
            }
        };
        // Inertia returns before onCancel/onFinish when another before listener
        // refuses the read. Observe after every listener can prevent that visit.
        const stopBefore = requireSuccess
            ? router.on?.('before', (event) => {
                  if (
                      event.detail.visit.method === 'get' &&
                      new URL(event.detail.visit.url, window.location.href)
                          .href === window.location.href
                  ) {
                      queueMicrotask(() => {
                          stopBefore?.();

                          if (event.defaultPrevented) {
                              finish();
                          }
                      });
                  }
              })
            : undefined;
        router.reload({
            ...options,
            ...(requireSuccess
                ? {
                      // The caller renders a recoverable failure after onFinish.
                      // Suppress Inertia's default error modal/global network throw.
                      onNetworkError: () => false,
                      onHttpException: () => false,
                  }
                : {}),
            onSuccess: (page) => {
                succeeded = true;
                refreshed = page;
                onSuccess?.(page);
            },
            onFinish: finish,
        });
    });
}
