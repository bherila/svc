/**
 * The one parser and formatter for a length of time, in whole minutes.
 *
 * The twin of `app/Support/Time/Duration.php`: both read
 * `tests/Fixtures/duration-cases.json`. Storage stays whole minutes; this only
 * decides what a person typed and what they are shown. A duration printed
 * without going through `formatDuration` is a defect - `duration-baseline.test.ts`
 * lists the raw `/ 60` conversions that predate this module so new ones fail.
 */

export type DurationStyle = 'decimal' | 'clock';

export const DEFAULT_DURATION_STYLE: DurationStyle = 'decimal';

/** Longer than any single entry could be. */
const MAX_MINUTES = 99999 * 60;

const UNIT_H = '(?:h|hr|hrs|hour|hours)';
const UNIT_M = 'm(?:in(?:ute)?s?)?';

function parseText(text: string): number | null {
    const clock = new RegExp('^(\\d{1,5}):([0-5]\\d)$').exec(text);

    if (clock) {
        return Number(clock[1]) * 60 + Number(clock[2]);
    }

    const minutes = new RegExp(`^(\\d{1,5}(?:\\.\\d+)?)\\s*${UNIT_M}$`).exec(
        text,
    );

    if (minutes) {
        return Math.round(Number(minutes[1]));
    }

    const hours = new RegExp(`^(\\d{1,5}(?:\\.\\d+)?)\\s*${UNIT_H}?$`).exec(
        text,
    );

    if (hours) {
        return Math.round(Number(hours[1]) * 60);
    }

    const both = new RegExp(
        `^(\\d{1,5})\\s*${UNIT_H}\\s*(\\d{1,2})\\s*${UNIT_M}$`,
    ).exec(text);

    if (both) {
        return Number(both[2]) < 60
            ? Number(both[1]) * 60 + Number(both[2])
            : null;
    }

    return null;
}

/**
 * Read `2:30`, `2.5`, `2.5h`, `2h30m`, `2h 30m`, `150m` or `150min` as minutes.
 *
 * Null for anything negative, empty or ambiguous (`2,5`, `2h30`, `1h 90m`)
 * rather than a guess, so the caller can leave the field alone instead of
 * writing a number the person did not mean.
 */
export function parseDuration(input: string): number | null {
    const text = input.trim().toLowerCase();

    if (text === '') {
        return null;
    }

    const minutes = parseText(text);

    return minutes !== null && minutes <= MAX_MINUTES ? minutes : null;
}

function decimal(minutes: number): string {
    const text = (Math.round(minutes) / 60)
        .toFixed(2)
        .replace(/0+$/, '')
        .replace(/\.$/, '');

    return `${text === '-0' ? '0' : text} h`;
}

function clock(minutes: number): string {
    const total = Math.abs(Math.round(minutes));

    return `${minutes < 0 ? '-' : ''}${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

export function formatDuration(minutes: number, style: DurationStyle): string {
    switch (style) {
        case 'decimal':
            return decimal(minutes);
        case 'clock':
            return clock(minutes);
    }
}

/** Invoices ignore the reader's preference, so a document means one thing. */
export function formatInvoiceDuration(minutes: number): string {
    return decimal(minutes);
}

/**
 * What the field understood, shown under it: "= 2.5 h (150 min)".
 * Null while the text says nothing usable.
 */
export function describeDuration(
    input: string,
    style: DurationStyle,
): string | null {
    const minutes = parseDuration(input);

    return minutes === null
        ? null
        : `= ${formatDuration(minutes, style)} (${minutes} min)`;
}

export function isDurationStyle(value: unknown): value is DurationStyle {
    return value === 'decimal' || value === 'clock';
}
