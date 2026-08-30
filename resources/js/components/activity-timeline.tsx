import { useMemo, useState } from 'react';

export type CompanyActivity = {
    id: string;
    action: string;
    actor_name: string | null;
    subject_type?: string | null;
    subject_id?: string | null;
    payload: Record<string, unknown>;
    created_at: string | null;
};

type ActivityTone = 'default' | 'green' | 'red' | 'blue';

type FormattedActivity = CompanyActivity & {
    title: string;
    subtitle?: string;
    tone: ActivityTone;
    isSystemNoise: boolean;
};

const actionTitles: Record<string, string> = {
    'company.updated': 'Company updated',
    'agreement.created': 'Agreement created',
    'agreement.activated': 'Agreement activated',
    'agreement.signed': 'Agreement signed',
    'agreement.transitioned': 'Agreement transitioned',
    'invoice.generated': 'Invoice generated',
    'invoice.updated': 'Invoice updated',
    'invoice.issued': 'Invoice issued',
    'invoice.voided': 'Invoice voided',
    'invoice.marked_paid': 'Invoice marked paid',
    'invoice.payment_received': 'Payment received',
    'invoice.payment_failed': 'Payment failed',
    'invoice.payment_canceled': 'Payment canceled',
    'invoice.payment_disputed': 'Payment disputed',
    'invoice.payment_refunded': 'Payment refunded',
    'payment_method.added': 'Payment method added',
    'payment_method.removed': 'Payment method removed',
    'payment_method.default_changed': 'Default payment method changed',
};

const meaningfulActions = new Set([
    'company.updated',
    'agreement.created',
    'agreement.activated',
    'agreement.signed',
    'agreement.transitioned',
    'invoice.issued',
    'invoice.voided',
    'invoice.marked_paid',
    'invoice.payment_received',
    'invoice.payment_failed',
    'invoice.payment_canceled',
    'invoice.payment_disputed',
    'invoice.payment_refunded',
    'payment_method.added',
    'payment_method.removed',
    'payment_method.default_changed',
]);

const redActions = new Set([
    'invoice.voided',
    'invoice.payment_failed',
    'invoice.payment_canceled',
    'invoice.payment_disputed',
    'payment_method.removed',
]);
const greenActions = new Set([
    'invoice.marked_paid',
    'invoice.payment_received',
    'agreement.signed',
    'payment_method.added',
]);
const blueActions = new Set([
    'invoice.issued',
    'agreement.created',
    'agreement.activated',
    'payment_method.default_changed',
]);

const toneClasses: Record<ActivityTone, string> = {
    default: 'bg-slate-400',
    green: 'bg-emerald-600',
    red: 'bg-red-600',
    blue: 'bg-cyan-700',
};

function titleFor(action: string): string {
    return (
        actionTitles[action] ?? action.replaceAll('.', ' ').replaceAll('_', ' ')
    );
}

function subtitleFor(payload: Record<string, unknown>): string | undefined {
    const imported = payload.external_payload;
    const displayPayload =
        imported && typeof imported === 'object' && !Array.isArray(imported)
            ? (imported as Record<string, unknown>)
            : payload;

    if (
        displayPayload.changes &&
        typeof displayPayload.changes === 'object' &&
        !Array.isArray(displayPayload.changes)
    ) {
        const changes = Object.entries(
            displayPayload.changes as Record<string, unknown>,
        )
            .flatMap(([field, change]) => {
                const pair =
                    Array.isArray(change) && change.length === 2
                        ? change
                        : change &&
                            typeof change === 'object' &&
                            'old' in change &&
                            'new' in change
                          ? [change.old, change.new]
                          : null;

                return pair
                    ? [
                          `${field.replaceAll('_', ' ')} ${String(pair[0])} → ${String(pair[1])}`,
                      ]
                    : [];
            })
            .slice(0, 3);

        if (changes.length > 0) {
            return changes.join(', ');
        }
    }

    return typeof displayPayload.invoice_kind === 'string' &&
        displayPayload.invoice_kind
        ? displayPayload.invoice_kind.replaceAll('_', ' ')
        : undefined;
}

function formatActivity(activity: CompanyActivity): FormattedActivity {
    let tone: ActivityTone = 'default';

    if (redActions.has(activity.action)) {
        tone = 'red';
    } else if (greenActions.has(activity.action)) {
        tone = 'green';
    } else if (blueActions.has(activity.action)) {
        tone = 'blue';
    }

    return {
        ...activity,
        title: titleFor(activity.action),
        subtitle: subtitleFor(activity.payload),
        tone,
        isSystemNoise: !meaningfulActions.has(activity.action),
    };
}

function ActivityRow({ activity }: { activity: FormattedActivity }) {
    return (
        <li className="rounded-xl border border-slate-200 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span
                        aria-hidden
                        className={`h-2 w-2 shrink-0 rounded-full ${toneClasses[activity.tone]}`}
                    />
                    <span className="font-medium">{activity.title}</span>
                </div>
                <time
                    className="text-xs text-slate-500"
                    dateTime={activity.created_at ?? undefined}
                >
                    {activity.created_at
                        ? new Date(activity.created_at).toLocaleString()
                        : ''}
                </time>
            </div>
            {activity.subtitle && (
                <p className="mt-1 text-sm text-slate-700">
                    {activity.subtitle}
                </p>
            )}
            <p className="mt-1 text-sm text-slate-500">
                {activity.actor_name ? `By ${activity.actor_name}` : 'System'}
            </p>
        </li>
    );
}

export function ActivityTimeline({
    activities,
}: {
    activities: CompanyActivity[];
}) {
    const [showSystem, setShowSystem] = useState(false);
    const { meaningful, system } = useMemo(() => {
        const formatted = activities.map(formatActivity);

        return {
            meaningful: formatted.filter((activity) => !activity.isSystemNoise),
            system: formatted.filter((activity) => activity.isSystemNoise),
        };
    }, [activities]);

    if (activities.length === 0) {
        return (
            <p className="mt-3 rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                No activity has been logged for this client yet.
            </p>
        );
    }

    return (
        <div className="mt-3 space-y-3">
            {meaningful.length === 0 && !showSystem && (
                <p className="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                    No notable activity. {system.length} system event
                    {system.length === 1 ? '' : 's'} hidden.
                </p>
            )}
            <ul className="space-y-2">
                {meaningful.map((activity) => (
                    <ActivityRow key={activity.id} activity={activity} />
                ))}
                {showSystem &&
                    system.map((activity) => (
                        <ActivityRow key={activity.id} activity={activity} />
                    ))}
            </ul>
            {system.length > 0 && (
                <button
                    type="button"
                    className="text-sm font-semibold text-cyan-700"
                    onClick={() => setShowSystem((shown) => !shown)}
                >
                    {showSystem ? 'Hide' : 'Show'} system activity (
                    {system.length})
                </button>
            )}
        </div>
    );
}
