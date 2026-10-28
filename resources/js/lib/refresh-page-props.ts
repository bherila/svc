import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';

type RefreshPagePropsOptions = {
    only?: string[];
    requireSuccess?: boolean;
    onFinish?: () => void;
    onSuccess?: (page: Page) => void;
};

/** Refresh authoritative props; callers retain their own mutation/retry policy. */
export function refreshPageProps({
    onFinish,
    onSuccess,
    requireSuccess = false,
    ...options
}: RefreshPagePropsOptions = {}): Promise<void> {
    return new Promise<void>((resolve, reject) => {
        let succeeded = false;
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
                    resolve();
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
            ...(onSuccess ? { onSuccess } : {}),
            ...(requireSuccess
                ? {
                      // The caller renders a recoverable failure after onFinish.
                      // Suppress Inertia's default error modal/global network throw.
                      onNetworkError: () => false,
                      onHttpException: () => false,
                      onSuccess: (page) => {
                          succeeded = true;
                          onSuccess?.(page);
                      },
                  }
                : {}),
            onFinish: finish,
        });
    });
}
