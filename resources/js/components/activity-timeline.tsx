import { useMemo, useState } from 'react';
import { formatDay, formatTimestamp } from '@/lib/datetime';

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
    'invoice.details_updated': 'Invoice details updated',
    'invoice.issued': 'Invoice issued',
    'invoice.corrected': 'Invoice corrected',
    'invoice.automatic_delivery_held': 'Automatic delivery held',
    'invoice.automatic_delivery_released': 'Automatic delivery released',
    'client.invoice_delivery_settings_updated':
        'Automatic delivery settings updated',
    'invoice.voided': 'Invoice voided',
    'invoice.marked_paid': 'Invoice marked paid',
    'invoice.payment_received': 'Payment received',
    'invoice.payment_failed': 'Payment failed',
    'invoice.payment_canceled': 'Payment canceled',
    'invoice.payment_disputed': 'Payment disputed',
    'invoice.payment_refunded': 'Payment refunded',
    'invoice.payment_date_corrected': 'Payment date corrected',
    'invoice.payment_corrected': 'Payment corrected',
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
    // A draft's due date moves what the client is told they owe and when.
    'invoice.details_updated',
    'invoice.corrected',
    'invoice.automatic_delivery_held',
    'invoice.automatic_delivery_released',
    'client.invoice_delivery_settings_updated',
    'invoice.voided',
    'invoice.marked_paid',
    'invoice.payment_received',
    'invoice.payment_failed',
    'invoice.payment_canceled',
    'invoice.payment_disputed',
    'invoice.payment_refunded',
    // A correction, not noise. The whole reason a date-only edit is allowed at
    // all is that the change is recorded, and an audit record hidden behind
    // "show system activity" is not one.
    'invoice.payment_date_corrected',
    // The general correction of a payment's method, reference, notes or date,
    // for the same reason: the recorded before/after and reason are the audit.
    'invoice.payment_corrected',
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

/**
 * Every changed field with its before and after, then the reason.
 *
 * Dates read as dates and an empty side reads as "none" rather than "null":
 * this is the audit record of a correction, so it is written for a person.
 */
function paymentCorrectionSubtitle(
    payload: Record<string, unknown>,
): string | undefined {
    const changes =
        payload.changes &&
        typeof payload.changes === 'object' &&
        !Array.isArray(payload.changes)
            ? (payload.changes as Record<string, unknown>)
            : {};
    const side = (field: string, value: unknown): string => {
        if (typeof value !== 'string' || value === '') {
            return 'none';
        }

        return field === 'received_on' ? formatDay(value) : value;
    };
    const parts = Object.entries(changes).flatMap(([field, change]) =>
        change && typeof change === 'object' && 'new' in change
            ? [
                  `${field.replaceAll('_', ' ')} ${side(field, (change as { old?: unknown }).old)} → ${side(field, change.new)}`,
              ]
            : [],
    );
    const reason =
        typeof payload.reason === 'string' && payload.reason !== ''
            ? `Reason: ${payload.reason}`
            : null;
    const text = [parts.join(', '), reason]
        .filter((part): part is string => !!part)
        .join(' · ');

    return text === '' ? undefined : text;
}

/** What a draft-details update changed: the due date's old and new day, and whether the notes moved. */
function invoiceDetailsSubtitle(
    payload: Record<string, unknown>,
): string | undefined {
    const day = (value: unknown): string =>
        typeof value === 'string' && value !== '' ? formatDay(value) : 'none';
    const due = payload.due_date;
    const parts: string[] = [];

    if (
        due &&
        typeof due === 'object' &&
        'old' in due &&
        'new' in due &&
        due.old !== due.new
    ) {
        parts.push(`due date ${day(due.old)} → ${day(due.new)}`);
    }

    if (payload.notes_changed === true) {
        parts.push('notes changed');
    }

    return parts.length > 0 ? parts.join(', ') : undefined;
}

function subtitleFor(
    payload: Record<string, unknown>,
    action: string,
): string | undefined {
    if (action === 'invoice.payment_corrected') {
        return paymentCorrectionSubtitle(payload);
    }

    if (action === 'invoice.details_updated') {
        return invoiceDetailsSubtitle(payload);
    }

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

    // A date correction's whole content is the two dates. Read off the action
    // rather than off the presence of the keys, because an imported
    // `external_payload` can carry a `received_on` of its own and that is a
    // payment's date rather than a change to one. `previous_received_on` is
    // nullable - an imported payment can carry no date at all - so the before
    // is allowed to be absent while the after is not.
    if (
        action === 'invoice.payment_date_corrected' &&
        typeof displayPayload.received_on === 'string'
    ) {
        const previous = displayPayload.previous_received_on;

        return `Received ${
            typeof previous === 'string' ? formatDay(previous) : 'not recorded'
        } → ${formatDay(displayPayload.received_on)}`;
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
        subtitle: subtitleFor(activity.payload, activity.action),
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
                    {activity.created_at === null
                        ? ''
                        : formatTimestamp(activity.created_at)}
                </time>
            </div>
            {activity.subtitle && (
                <p className="mt-1 text-sm wrap-anywhere text-slate-700">
                    {activity.subtitle}
                </p>
            )}
            <p className="mt-1 text-sm wrap-anywhere text-slate-500">
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
