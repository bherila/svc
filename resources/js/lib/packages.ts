import { statusLabel } from '@/lib/labels';
import { formatMoney } from '@/lib/money';

/** Every cadence the server offers, as words. Unknown ones read as words too. */
const CADENCE_LABELS: Record<string, string> = {
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    semi_annual: 'Every 6 months',
    annual: 'Annually',
    one_time: 'One time',
};

/** "per month", for a price line. */
const CADENCE_PER: Record<string, string> = {
    monthly: 'per month',
    quarterly: 'per quarter',
    semi_annual: 'every 6 months',
    annual: 'per year',
    one_time: 'once',
};

export function cadenceLabel(cadence: string): string {
    return CADENCE_LABELS[cadence] ?? statusLabel(cadence);
}

export function cadencePer(cadence: string): string {
    return CADENCE_PER[cadence] ?? statusLabel(cadence).toLowerCase();
}

/** "$45.00 per month", or "$900.00 once". */
export function priceLine(
    amount: number,
    currency: string,
    cadence: string,
): string {
    return `${formatMoney(amount, currency)} ${cadencePer(cadence)}`;
}

/** A stored minor-unit amount as the text an input holds: 4500 -> "45.00". */
export function majorText(minor: number | null | undefined): string {
    return minor === null || minor === undefined
        ? ''
        : (minor / 100).toFixed(2);
}

/**
 * Markdown as blocks of plain text: "- " and "* " lines are list items, other
 * runs of lines are paragraphs. Nothing is interpreted as HTML, so visitor-facing
 * copy typed by an operator can never inject markup.
 */
export type TextBlock =
    { type: 'paragraph'; text: string } | { type: 'list'; items: string[] };

export function textBlocks(markdown: string | null): TextBlock[] {
    const blocks: TextBlock[] = [];

    for (const raw of (markdown ?? '').split(/\r?\n/)) {
        const line = raw.trim();

        if (line === '') {
            continue;
        }

        const item = /^[-*]\s+(.*)$/.exec(line);
        const last = blocks.at(-1);

        if (item) {
            if (last?.type === 'list') {
                last.items.push(item[1]);
            } else {
                blocks.push({ type: 'list', items: [item[1]] });
            }
        } else if (last?.type === 'paragraph') {
            last.text += ` ${line}`;
        } else {
            blocks.push({ type: 'paragraph', text: line });
        }
    }

    return blocks;
}
