import { router } from '@inertiajs/react';

type RefreshPagePropsOptions = {
    only?: string[];
    onFinish?: () => void;
};

/** Refresh authoritative props; callers retain their own mutation/retry policy. */
export function refreshPageProps({
    onFinish,
    ...options
}: RefreshPagePropsOptions = {}): Promise<void> {
    return new Promise<void>((resolve) => {
        router.reload({
            ...options,
            onFinish: () => {
                try {
                    onFinish?.();
                } finally {
                    resolve();
                }
            },
        });
    });
}
