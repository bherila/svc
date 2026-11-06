import { usePage } from '@inertiajs/react';
import { DEFAULT_DURATION_STYLE, isDurationStyle } from '@/lib/duration';
import type { DurationStyle } from '@/lib/duration';
import type { Auth } from '@/types/auth';

/**
 * How the signed-in person reads time ("Show time as 2.5 h / 2:30"), shared on
 * every request. Invoices ignore it and use `formatInvoiceDuration`.
 */
export function useDurationStyle(): DurationStyle {
    const auth = usePage().props.auth as Auth | undefined;
    const style = auth?.user?.duration_style;

    return isDurationStyle(style) ? style : DEFAULT_DURATION_STYLE;
}
