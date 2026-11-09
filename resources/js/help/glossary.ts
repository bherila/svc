/**
 * The one glossary.
 *
 * Every word a new user may not know is defined here once, and the same
 * wording is the vocabulary for emails, PDFs and MCP descriptions. A term is
 * *defined* by exactly one help article (its `terms` front matter), which the
 * coverage tests enforce.
 */
export type GlossaryEntry = { term: string; definition: string };

export const GLOSSARY: readonly GlossaryEntry[] = [
    {
        term: 'Draft',
        definition:
            'Saved but not yet sent on. A draft can still be edited or thrown away.',
    },
    {
        term: 'Submitted',
        definition:
            'Handed in for review. The person who did the work can no longer change it.',
    },
    {
        term: 'Reviewed',
        definition:
            'Checked by the business that will invoice it, and waiting for the client to approve.',
    },
    {
        term: 'Invoiced',
        definition:
            'Already billed on an issued invoice. It is locked and counts toward what the client owes.',
    },
    {
        term: 'Approved',
        definition:
            'Accepted as correct and ready to bill. Approved work goes onto the next invoice.',
    },
    {
        term: 'Disputed',
        definition:
            'Questioned by the client. It stays out of billing until the question is settled.',
    },
    {
        term: 'Written off',
        definition:
            'Disputed and settled by not billing it. It will never appear on an invoice.',
    },
    {
        term: 'Retainer',
        definition:
            'A block of hours the client pays for each period, whether or not they are all used.',
    },
    {
        term: 'Rollover',
        definition:
            'Retainer hours left unused at the end of a period that carry into the next one.',
    },
    {
        term: 'Overage',
        definition:
            'Hours worked beyond what the retainer covers, billed at the hourly rate.',
    },
    {
        term: 'Service package',
        definition:
            'A fixed bundle of services at a fixed price that clients can request from its public page or add from their portal.',
    },
    {
        term: 'Reviewer access',
        definition:
            'Permission a business gives someone outside it to approve or dispute the hours and expenses it bills one client for.',
    },
    {
        term: 'Shared project',
        definition:
            'A project run by one business with another business taking part in it.',
    },
    {
        term: 'MRR',
        definition:
            'Monthly recurring revenue: what recurring charges add up to in a month. Annual items count one twelfth, quarterly one third. One-time charges are left out.',
    },
    {
        term: 'Contracted',
        definition:
            'Revenue the agreements on file already commit the client to. It stops when an agreement ends; no renewal is assumed.',
    },
    {
        term: 'Projected overage',
        definition:
            'A guess at hours beyond the retainer, from the last six months of billed overage. It is off unless you turn it on.',
    },
    {
        term: 'Basis',
        definition:
            'Which money a figure counts. Invoiced counts what was billed, in the month it was for. Collected counts payments received, net of refunds.',
    },
    {
        term: 'Approved timesheet',
        definition:
            'The hours someone else approved for one calendar month, with who worked each and who approved it. You invoice from it or send it.',
    },
    {
        term: 'Partner',
        definition:
            'An independent business that does part of the work on a shared project.',
    },
];

export function glossaryDefinition(term: string): string | null {
    return (
        GLOSSARY.find(
            (entry) => entry.term.toLowerCase() === term.toLowerCase(),
        )?.definition ?? null
    );
}
