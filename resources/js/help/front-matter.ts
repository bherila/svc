export type HelpAudience = 'operator' | 'client' | 'partner';

export const HELP_AUDIENCES: readonly HelpAudience[] = [
    'operator',
    'client',
    'partner',
];

export type HelpArticle = {
    slug: string;
    title: string;
    audience: HelpAudience[];
    /** Laravel route names this article answers questions for. */
    routes: string[];
    /** Glossary terms this article defines. */
    terms: string[];
    body: string;
};

function list(value: string): string[] {
    const inner = value.trim().replace(/^\[/, '').replace(/\]$/, '');

    return inner
        .split(',')
        .map((item) => item.trim())
        .filter((item) => item !== '');
}

/**
 * Split an article into its front matter and body.
 *
 * Deliberately not YAML: the front matter is `key: value` lines, with lists
 * written `[a, b]`. An article that does not parse throws, so a typo fails the
 * build and the coverage tests rather than quietly dropping out of the panel.
 */
export function parseArticle(slug: string, raw: string): HelpArticle {
    const match = /^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/.exec(raw);

    if (!match) {
        throw new Error(`Help article "${slug}" has no front matter.`);
    }

    const fields = new Map<string, string>();

    for (const line of match[1].split(/\r?\n/)) {
        const field = /^([a-z]+):\s*(.*)$/.exec(line);

        if (!field) {
            throw new Error(
                `Help article "${slug}" has an unreadable front matter line: ${line}`,
            );
        }

        fields.set(field[1], field[2]);
    }

    const title = fields.get('title')?.trim() ?? '';

    if (title === '') {
        throw new Error(`Help article "${slug}" has no title.`);
    }

    const audience = list(fields.get('audience') ?? '');

    for (const entry of audience) {
        if (!(HELP_AUDIENCES as readonly string[]).includes(entry)) {
            throw new Error(
                `Help article "${slug}" names an unknown audience "${entry}".`,
            );
        }
    }

    if (audience.length === 0) {
        throw new Error(`Help article "${slug}" names no audience.`);
    }

    return {
        slug,
        title,
        audience: audience as HelpAudience[],
        routes: list(fields.get('routes') ?? ''),
        terms: list(fields.get('terms') ?? ''),
        body: match[2].trim(),
    };
}
