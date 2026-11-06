import { usePage } from '@inertiajs/react';
import { ArrowLeftIcon, SearchIcon, XIcon } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import {
    setHelpOpen,
    toggleHelp,
    useHelpOpen,
} from '@/components/help/help-store';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    articlesForAudience,
    articlesForRoute,
    audienceForRoute,
    searchArticles,
} from '@/help/articles';
import type { HelpArticle } from '@/help/front-matter';
import { Markdown } from '@/help/markdown';

/**
 * Help, beside the page.
 *
 * Opens from the `?` button in the navbar or with Cmd+/ (Ctrl+/), shows this
 * route's articles first and searches everything the viewer's audience may read.
 * A non-modal dialog: the page stays usable beside it, so it traps no focus,
 * but focus moves in on open and returns to where it came from on close.
 */
export function HelpPanel({
    articles,
}: {
    /** Overridable for tests; defaults to the bundled articles. */
    articles?: readonly HelpArticle[];
}) {
    const open = useHelpOpen();
    const props = usePage().props as { routeName?: string | null };
    const routeName = props.routeName ?? null;
    const titleId = useId();
    const heading = useRef<HTMLHeadingElement>(null);
    const returnTo = useRef<HTMLElement | null>(null);
    const [query, setQuery] = useState('');
    const [slug, setSlug] = useState<string | null>(null);
    const [anchor, setAnchor] = useState<string | null>(null);

    const readable = useMemo(
        () =>
            articles
                ? [...articles]
                : articlesForAudience(audienceForRoute(routeName)),
        [articles, routeName],
    );
    const here = articlesForRoute(routeName, readable);
    const results = searchArticles(query, readable);
    const others = results.filter((article) => !here.includes(article));
    const shownHere =
        query.trim() === '' ? here : results.filter((a) => here.includes(a));
    const article =
        readable.find((candidate) => candidate.slug === slug) ?? null;

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === '/' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                toggleHelp();
            } else if (event.key === 'Escape') {
                // On the document, not the panel: after following a link the
                // clicked element is gone and focus falls back to the body.
                setHelpOpen(false);
            }
        };
        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    useEffect(() => {
        if (open) {
            returnTo.current =
                document.activeElement instanceof HTMLElement
                    ? document.activeElement
                    : null;
            heading.current?.focus();
        } else {
            returnTo.current?.focus();
            returnTo.current = null;
        }
    }, [open]);

    useEffect(() => {
        if (anchor !== null) {
            document
                .getElementById(`help-${anchor}`)
                ?.scrollIntoView({ block: 'start' });
        }
    }, [anchor, slug]);

    if (!open) {
        return null;
    }

    const navigate = (target: string) => {
        if (target.startsWith('#')) {
            setAnchor(target.slice(1));

            return;
        }

        const [nextSlug, nextAnchor] = target.replace(/^help:/, '').split('#');
        setSlug(nextSlug);
        setAnchor(nextAnchor ?? null);
    };

    const row = (item: HelpArticle) => (
        <li key={item.slug}>
            <button
                type="button"
                onClick={() => navigate(`help:${item.slug}`)}
                className="w-full rounded-lg px-2 py-2 text-start text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {item.title}
            </button>
        </li>
    );

    return (
        <div
            role="dialog"
            aria-modal="false"
            aria-labelledby={titleId}
            data-test="help-panel"
            className="fixed inset-y-0 end-0 z-50 flex w-full flex-col border-s border-border bg-background text-foreground shadow-xl sm:w-96"
        >
            <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                {article !== null && (
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Back to all help"
                        onClick={() => {
                            setSlug(null);
                            setAnchor(null);
                        }}
                    >
                        <ArrowLeftIcon aria-hidden="true" />
                    </Button>
                )}
                <h2
                    id={titleId}
                    ref={heading}
                    tabIndex={-1}
                    className="min-w-0 flex-1 truncate text-base font-semibold outline-none"
                >
                    {article?.title ?? 'Help'}
                </h2>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Close help"
                    onClick={() => setHelpOpen(false)}
                >
                    <XIcon aria-hidden="true" />
                </Button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto p-4">
                {article !== null ? (
                    <Markdown
                        source={article.body}
                        idPrefix="help-"
                        onNavigate={navigate}
                    />
                ) : (
                    <div className="space-y-4">
                        <div className="relative">
                            <SearchIcon
                                aria-hidden="true"
                                className="pointer-events-none absolute start-2.5 top-2.5 size-4 text-muted-foreground"
                            />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                aria-label="Search help"
                                placeholder="Search help"
                                className="ps-8"
                            />
                        </div>

                        {shownHere.length > 0 && (
                            <section aria-label="On this page">
                                <h3 className="px-2 text-xs font-medium text-muted-foreground">
                                    On this page
                                </h3>
                                <ul>{shownHere.map(row)}</ul>
                            </section>
                        )}

                        <section aria-label="All articles">
                            <h3 className="px-2 text-xs font-medium text-muted-foreground">
                                {shownHere.length > 0
                                    ? 'Everything else'
                                    : 'All articles'}
                            </h3>
                            <ul>{others.map(row)}</ul>
                            {results.length === 0 && (
                                <p className="px-2 py-3 text-sm text-muted-foreground">
                                    Nothing matches that.
                                </p>
                            )}
                        </section>
                    </div>
                )}
            </div>
        </div>
    );
}
