import { router } from '@inertiajs/react';

type RefreshPagePropsOptions = {
    only?: string[];
    requireSuccess?: boolean;
    onFinish?: () => void;
};

/** Refresh authoritative props; callers retain their own mutation/retry policy. */
export function refreshPageProps({
    onFinish,
    requireSuccess = false,
    ...options
}: RefreshPagePropsOptions = {}): Promise<void> {
    return new Promise<void>((resolve, reject) => {
        let succeeded = false;
        router.reload({
            ...options,
            ...(requireSuccess
                ? {
                      onSuccess: () => {
                          succeeded = true;
                      },
                  }
                : {}),
            onFinish: () => {
                try {
                    onFinish?.();
                } finally {
                    if (requireSuccess && !succeeded) {
                        reject(
                            new Error(
                                'Current page props could not be refreshed.',
                            ),
                        );
                    } else {
                        resolve();
                    }
                }
            },
        });
    });
}
