/**
 * Dates and times, in the reader's own clock.
 *
 * Two kinds of value arrive from the server and they are not the same kind of
 * thing, which is the whole reason this file exists:
 *
 * - An **instant** — `activated_at`, `signed_at`, `sent_at` — is a moment,
 *   serialized as ISO 8601 in UTC. It happened at one point in time and every
 *   reader should see it on their own clock. Printed straight from the column
 *   it read `2026-01-01T00:00:00.000000Z`, which is not a time anybody tells.
 * - A **calendar date** — `starts_on`, `ends_on`, `issue_date`, `line_date` —
 *   is a day on a calendar, serialized `YYYY-MM-DD`. It has no time and no
 *   zone. Feeding one to `new Date()` parses it as UTC midnight, so a reader
 *   west of Greenwich is shown the day before: an agreement starting on the
 *   1st appears to start on the 31st. That off-by-one is silent, plausible,
 *   and lands on money.
 *
 * So instants convert and calendar dates are formatted from their own parts,
 * never through a timezone. Keeping both here makes choosing between them a
 * decision at the call site rather than an accident of which helper was
 * nearest.
 *
 * Formatting is `Intl`, with the locale left undefined so it follows the
 * browser rather than a language we picked. Nothing here throws: an unparseable
 * value comes back as the em dash every other absent field uses, because a
 * screen showing a stack trace where a date goes is worse than one saying
 * nothing.
 */

const ABSENT = '—';

/** ISO 8601 instant → the reader's date and time, e.g. "1 Jan 2026, 09:00". */
export function formatTimestamp(value: string | null | undefined): string {
    const parsed = instant(value);

    if (parsed === null) {
        return ABSENT;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(parsed);
}

/** ISO 8601 instant → the reader's date alone, where the time adds nothing. */
export function formatTimestampDate(value: string | null | undefined): string {
    const parsed = instant(value);

    if (parsed === null) {
        return ABSENT;
    }

    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
        parsed,
    );
}

/**
 * `YYYY-MM-DD` → the same day, written the reader's way.
 *
 * Built from the parts rather than parsed, so the day on the screen is the day
 * in the column whatever zone the reader is in.
 */
export function formatDay(value: string | null | undefined): string {
    if (typeof value !== 'string') {
        return ABSENT;
    }

    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());

    if (parts === null) {
        return value.trim() === '' ? ABSENT : value;
    }

    const year = Number(parts[1]);
    const month = Number(parts[2]);
    const day = Number(parts[3]);

    // Noon UTC, and only as a carrier for the formatter. Midnight would let a
    // negative offset roll the date backwards before it is ever printed, which
    // is the bug this function exists to avoid.
    const carrier = new Date(Date.UTC(year, month - 1, day, 12));

    if (Number.isNaN(carrier.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeZone: 'UTC',
    }).format(carrier);
}

/**
 * `YYYY-MM-DD` → a short day for a dense column, e.g. "Mon, 1 Jun".
 *
 * The time sheet's form: no year, because every row on that screen is inside a
 * month the heading already names, and a weekday is what an operator scans for
 * when they are looking for the Friday they forgot to log.
 *
 * Local midnight rather than `Date.UTC`: `new Date('2026-01-01T00:00:00')` -
 * no zone suffix - is parsed on the reader's own clock, so the day survives.
 * Adding a `Z` here, or handing the string to `new Date('2026-01-01')`, is the
 * off-by-one described at the top of this file.
 */
export function formatShortDay(value: string): string {
    const date = new Date(`${value}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

/** The absent marker, so callers do not each invent their own dash. */
export function absentDate(): string {
    return ABSENT;
}

function instant(value: string | null | undefined): Date | null {
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
}
