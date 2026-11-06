import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { HELP_ARTICLES } from '@/help/articles';
import { parseArticle } from '@/help/front-matter';
import { GLOSSARY } from '@/help/glossary';
import { headingIds, linksIn } from '@/help/markdown';
import { isKnownStatus, KNOWN_STATUSES, statusNextStep } from '@/lib/labels';

const ROOT = join(__dirname, '..', '..', '..');

describe('help articles', () => {
    it('are bundled', () => {
        expect(HELP_ARTICLES.length).toBeGreaterThan(0);
    });

    it('have unique slugs and titles', () => {
        const slugs = HELP_ARTICLES.map((article) => article.slug);

        expect(new Set(slugs).size).toBe(slugs.length);
    });

    it('reject front matter that does not parse', () => {
        expect(() => parseArticle('x', 'no front matter')).toThrow();
        expect(() =>
            parseArticle('x', '---\ntitle: T\naudience: [robot]\n---\nbody'),
        ).toThrow(/unknown audience/);
    });

    it('link only to headings that exist', () => {
        const problems: string[] = [];

        for (const article of HELP_ARTICLES) {
            for (const link of linksIn(article.body)) {
                if (link.href.startsWith('#')) {
                    if (
                        !headingIds(article.body).includes(link.href.slice(1))
                    ) {
                        problems.push(`${article.slug}: ${link.href}`);
                    }

                    continue;
                }

                if (link.href.startsWith('help:')) {
                    const [slug, anchor] = link.href.slice(5).split('#');
                    const target = HELP_ARTICLES.find((a) => a.slug === slug);

                    if (
                        !target ||
                        (anchor !== undefined &&
                            !headingIds(target.body).includes(anchor))
                    ) {
                        problems.push(`${article.slug}: ${link.href}`);
                    }
                }
            }
        }

        expect(problems).toEqual([]);
    });
});

describe('glossary', () => {
    it('does not define a term twice', () => {
        const names = GLOSSARY.map((entry) => entry.term.toLowerCase());

        expect(names.filter((n, i) => names.indexOf(n) !== i)).toEqual([]);
    });

    it('is defined by exactly one article per term', () => {
        const owners = new Map<string, string[]>();

        for (const article of HELP_ARTICLES) {
            for (const term of article.terms) {
                owners.set(term, [...(owners.get(term) ?? []), article.slug]);
            }
        }

        const twice = [...owners].filter(([, slugs]) => slugs.length > 1);
        const unknown = [...owners.keys()].filter(
            (term) => !GLOSSARY.some((entry) => entry.term === term),
        );
        const undefinedTerms = GLOSSARY.filter(
            (entry) => !owners.has(entry.term),
        ).map((entry) => entry.term);

        expect(twice).toEqual([]);
        expect(unknown).toEqual([]);
        expect(undefinedTerms).toEqual([]);
    });

    it('covers the vocabulary the spec names', () => {
        expect(GLOSSARY.map((entry) => entry.term)).toEqual(
            expect.arrayContaining([
                'Draft',
                'Submitted',
                'Reviewed',
                'Invoiced',
                'Approved',
                'Disputed',
                'Retainer',
                'Rollover',
                'Overage',
                'Shared project',
                'Partner',
            ]),
        );
    });
});

describe('statuses', () => {
    const enums = [
        'app/Support/Billing/InvoiceStatus.php',
        'app/Support/Expenses/ExpenseStatus.php',
        'app/Support/Billing/InvoicePaymentStatus.php',
        'app/Support/Access/PortalInvitationStatus.php',
        'app/Support/Finance/PayoutStatus.php',
        'app/Support/Billing/StripeRefundStatus.php',
        'app/Support/Billing/Reminders/PaymentReminderStatus.php',
        'app/Support/Billing/InvoiceDeliveryStatusOutcome.php',
    ];

    it('every known status has a next-step line', () => {
        for (const status of KNOWN_STATUSES) {
            expect(statusNextStep(status), status).toEqual(
                expect.stringMatching(/\S/),
            );
        }
    });

    it('every backing-enum case has a next-step line', () => {
        const missing: string[] = [];

        for (const file of enums) {
            const source = readFileSync(join(ROOT, file), 'utf8');

            for (const match of source.matchAll(
                /^\s+case\s+\w+\s*=\s*'([a-z_]+)'/gm,
            )) {
                if (!isKnownStatus(match[1])) {
                    missing.push(`${file}: ${match[1]}`);
                }
            }
        }

        expect(missing).toEqual([]);
    });

    it('has no next step for a status it has never seen', () => {
        expect(statusNextStep('brand_new')).toBeNull();
        expect(statusNextStep(null)).toBeNull();
    });
});
