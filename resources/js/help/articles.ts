import { parseArticle } from '@/help/front-matter';
import type { HelpArticle, HelpAudience } from '@/help/front-matter';

/**
 * Every article in `docs/help`, bundled at build time.
 *
 * Help changes in the same commit as the feature it explains, so it ships in
 * the bundle rather than being fetched.
 */
const RAW = import.meta.glob('../../../docs/help/*.md', {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;

export const HELP_ARTICLES: readonly HelpArticle[] = Object.entries(RAW)
    .map(([path, raw]) =>
        parseArticle(path.replace(/^.*\/([^/]+)\.md$/, '$1'), raw),
    )
    .sort((a, b) => a.title.localeCompare(b.title));

export function audienceForRoute(routeName: string | null): HelpAudience {
    return routeName !== null && routeName.startsWith('portal.')
        ? 'client'
        : 'operator';
}

export function articlesForAudience(audience: HelpAudience): HelpArticle[] {
    return HELP_ARTICLES.filter((article) =>
        article.audience.includes(audience),
    );
}

/** Articles that belong to this page, first. */
export function articlesForRoute(
    routeName: string | null,
    articles: readonly HelpArticle[] = HELP_ARTICLES,
): HelpArticle[] {
    return routeName === null
        ? []
        : articles.filter((article) => article.routes.includes(routeName));
}

export function searchArticles(
    query: string,
    articles: readonly HelpArticle[],
): HelpArticle[] {
    const words = query.toLowerCase().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return [...articles];
    }

    return articles.filter((article) => {
        const haystack =
            `${article.title} ${article.terms.join(' ')} ${article.body}`.toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
}
