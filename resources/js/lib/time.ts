/**
 * Duration formatting for the time sheet.
 *
 * Time is stored as whole minutes and read as `h:mm`. The predecessor showed
 * the same, and it matters more than presentation: an operator comparing a row
 * against a retainer is comparing hours, and a decimal like `1.75` invites the
 * reading `1 hour 75 minutes`.
 */

export function formatHours(minutes: number): string {
    const sign = minutes < 0 ? '-' : '';
    const total = Math.abs(Math.round(minutes));
    const hours = Math.floor(total / 60);
    const rest = total % 60;

    return `${sign}${hours}:${String(rest).padStart(2, '0')}`;
}

/** Hours as a decimal, for capacity figures the ledger reports that way. */
export function formatDecimalHours(hours: number): string {
    return hours.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/**
 * Read `1:30`, `1.5` or `90m` as minutes.
 *
 * Returns null rather than a guess when the text says nothing usable, so the
 * caller can leave the field alone instead of writing a zero.
 */
export function parseDuration(input: string): number | null {
    const text = input.trim().toLowerCase();

    if (text === '') {
        return null;
    }

    const clock = /^(\d+):([0-5]\d)$/.exec(text);

    if (clock) {
        return Number(clock[1]) * 60 + Number(clock[2]);
    }

    const minutes = /^(\d+(?:\.\d+)?)\s*m(?:in(?:utes?)?)?$/.exec(text);

    if (minutes) {
        return Math.round(Number(minutes[1]));
    }

    const hours = /^(\d+(?:\.\d+)?)\s*h(?:ours?|rs?)?$/.exec(text);

    if (hours) {
        return Math.round(Number(hours[1]) * 60);
    }

    const bare = /^(\d+(?:\.\d+)?)$/.exec(text);

    if (bare) {
        return Math.round(Number(bare[1]) * 60);
    }

    return null;
}

export function formatDate(iso: string): string {
    const date = new Date(`${iso}T00:00:00`);

    return date.toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

/**
 * Today, in the browser's own calendar.
 *
 * `toISOString()` converts to UTC before formatting, so an operator west of
 * UTC in the evening gets tomorrow's date and one east of it after midnight
 * gets yesterday's - silently, on the field that decides which billing cycle
 * the work lands in.
 */
export function todayLocal(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}
