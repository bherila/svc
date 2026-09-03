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

/**
 * Today on a given calendar, computed now.
 *
 * Two hazards, and only naming both avoids trading one for the other.
 * `toISOString()` formats in UTC, so an operator west of it gets tomorrow's
 * date in the evening - silently, on the field that decides which billing
 * cycle the work lands in. And the workspace has a calendar of its own that
 * the browser's need not match; the write validators bound the date by the
 * workspace's window, so a browser ahead of it defaults to a date its own
 * save refuses.
 *
 * Taking the date from the server would fix both and introduce a third: the
 * sheet is a long-lived page, and a value baked in at render is yesterday's
 * by morning. So the server sends the timezone and this reads the clock.
 */
export function todayIn(timeZone: string): string {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());

    const value = (type: string) =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${value('year')}-${value('month')}-${value('day')}`;
}
