import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { apiRequest } from '@/lib/api';
import { formatTimestamp } from '@/lib/datetime';
import { cn } from '@/lib/utils';

export interface WebhookFixView {
    action: string;
    label: string;
    confirm_title: string;
    confirm_body: string;
    url: string;
}

/** The stored result of the Stripe webhook check, finished by the server. */
export interface WebhookHealthView {
    severity: 'ok' | 'warning' | 'unknown';
    summary: string;
    detail: string | null;
    missing: string[];
    endpoint_url: string | null;
    checked_at: string | null;
    check_url: string | null;
    fix: WebhookFixView | null;
}

/**
 * Whether Stripe's webhook endpoint is subscribed to every event SVC needs:
 * the stored answer, a Check again button and, for an owner or admin, the fix
 * behind an explicit confirmation. Nothing here decides anything; the server
 * sends the sentences, the URLs and the one fix that applies.
 */
export function WebhookHealth({ health }: { health: WebhookHealthView }) {
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);

    async function checkAgain() {
        if (health.check_url === null) {
            return;
        }

        setBusy(true);
        setError(null);
        setDone(null);

        const result = await apiRequest('POST', health.check_url, {});

        setBusy(false);

        if (!result.ok) {
            setError(result.message);

            return;
        }

        router.reload();
    }

    async function fix() {
        if (health.fix === null) {
            return;
        }

        setBusy(true);
        setError(null);

        const result = await apiRequest<{ message: string }>(
            'POST',
            health.fix.url,
            { action: health.fix.action },
        );

        setBusy(false);
        setConfirming(false);

        if (!result.ok) {
            setError(result.errors?.webhook?.[0] ?? result.message);

            return;
        }

        setDone(result.data.message);
        router.reload();
    }

    const warning = health.severity === 'warning';

    return (
        <div
            data-test="stripe-webhook-health"
            className={cn(
                'grid min-w-0 grid-cols-1 gap-2 rounded-lg border px-3 py-2 text-sm',
                warning
                    ? 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-300'
                    : 'border-border',
            )}
        >
            <p
                className="font-medium wrap-anywhere"
                data-test="stripe-webhook-summary"
                role={warning ? 'alert' : 'status'}
            >
                {health.summary}
            </p>
            {health.detail !== null && (
                <p className="wrap-anywhere">{health.detail}</p>
            )}
            {health.endpoint_url !== null && (
                <p>
                    <span
                        className={
                            warning ? undefined : 'text-muted-foreground'
                        }
                    >
                        Endpoint{' '}
                    </span>
                    <span
                        className="font-mono wrap-anywhere"
                        data-test="stripe-webhook-url"
                    >
                        {health.endpoint_url}
                    </span>
                </p>
            )}
            <p className={cn('text-xs', !warning && 'text-muted-foreground')}>
                {health.checked_at === null
                    ? 'Not checked yet.'
                    : `Checked ${formatTimestamp(health.checked_at)}.`}
            </p>
            {done !== null && (
                <p role="status" className="wrap-anywhere">
                    {done}
                </p>
            )}
            {error !== null && (
                <p role="alert" className="wrap-anywhere text-destructive">
                    {error}
                </p>
            )}
            {(health.check_url !== null || health.fix !== null) && (
                <div className="flex flex-wrap gap-2">
                    {health.check_url !== null && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => void checkAgain()}
                        >
                            Check again
                        </Button>
                    )}
                    {health.fix !== null && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => setConfirming(true)}
                        >
                            {health.fix.label}
                        </Button>
                    )}
                </div>
            )}

            {health.fix !== null && (
                <AlertDialog
                    open={confirming}
                    onOpenChange={(open: boolean) => {
                        if (!busy) {
                            setConfirming(open);
                        }
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                {health.fix.confirm_title}
                            </AlertDialogTitle>
                            <AlertDialogDescription className="wrap-anywhere">
                                {health.fix.confirm_body}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={busy}>
                                Cancel
                            </AlertDialogCancel>
                            <AlertDialogAction
                                disabled={busy}
                                onClick={(event: React.MouseEvent) => {
                                    event.preventDefault();
                                    void fix();
                                }}
                            >
                                {health.fix.label}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
        </div>
    );
}
