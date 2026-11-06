import type { ReactNode } from 'react';

/**
 * A minimal, safe renderer for the help articles.
 *
 * No markdown dependency exists in the bundle, and the articles need very
 * little: headings, paragraphs, lists, emphasis, code and links. It builds React
 * elements and never HTML strings, so article text cannot inject markup, and a
 * link is only followed when it is an in-article anchor, a `help:` reference to
 * another article, or an https URL.
 */

export function slugify(text: string): string {
    return text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

type Block =
    | { kind: 'heading'; level: number; text: string; id: string }
    | { kind: 'paragraph'; text: string }
    | { kind: 'list'; ordered: boolean; items: string[] };

export function parseBlocks(source: string): Block[] {
    const blocks: Block[] = [];
    const seen = new Map<string, number>();
    let paragraph: string[] = [];
    let list: { ordered: boolean; items: string[] } | null = null;

    const flush = () => {
        if (paragraph.length > 0) {
            blocks.push({ kind: 'paragraph', text: paragraph.join(' ') });
            paragraph = [];
        }

        if (list !== null) {
            blocks.push({ kind: 'list', ...list });
            list = null;
        }
    };

    for (const line of source.split(/\r?\n/)) {
        const heading = /^(#{1,4})\s+(.+?)\s*#*$/.exec(line);
        const bullet = /^\s*[-*]\s+(.+)$/.exec(line);
        const numbered = /^\s*\d+[.)]\s+(.+)$/.exec(line);

        if (heading) {
            flush();

            const base = slugify(heading[2]);
            const count = seen.get(base) ?? 0;
            seen.set(base, count + 1);
            blocks.push({
                kind: 'heading',
                level: heading[1].length,
                text: heading[2],
                id: count === 0 ? base : `${base}-${count + 1}`,
            });
        } else if (bullet || numbered) {
            const ordered = numbered !== null && bullet === null;
            const text = (bullet ?? numbered)![1];

            if (
                paragraph.length > 0 ||
                (list !== null && list.ordered !== ordered)
            ) {
                flush();
            }

            list ??= { ordered, items: [] };
            list.items.push(text);
        } else if (line.trim() === '') {
            flush();
        } else {
            if (list !== null) {
                flush();
            }

            paragraph.push(line.trim());
        }
    }

    flush();

    return blocks;
}

export function headingIds(source: string): string[] {
    return parseBlocks(source).flatMap((block) =>
        block.kind === 'heading' ? [block.id] : [],
    );
}

export type HelpLink = { text: string; href: string };

const INLINE = /(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`|\[[^\]]+\]\([^)]+\))/g;

export function linksIn(source: string): HelpLink[] {
    return [...source.matchAll(/\[([^\]]+)\]\(([^)]+)\)/g)].map((match) => ({
        text: match[1],
        href: match[2],
    }));
}

function inline(
    text: string,
    onNavigate: (target: string) => void,
    keyPrefix: string,
): ReactNode[] {
    return text.split(INLINE).map((part, index) => {
        const key = `${keyPrefix}-${index}`;

        if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
            return <strong key={key}>{part.slice(2, -2)}</strong>;
        }

        if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
            return (
                <code key={key} className="rounded bg-muted px-1 text-xs">
                    {part.slice(1, -1)}
                </code>
            );
        }

        if (part.startsWith('*') && part.endsWith('*') && part.length > 2) {
            return <em key={key}>{part.slice(1, -1)}</em>;
        }

        const link = /^\[([^\]]+)\]\(([^)]+)\)$/.exec(part);

        if (link) {
            const href = link[2];

            if (href.startsWith('#') || href.startsWith('help:')) {
                return (
                    <a
                        key={key}
                        href={href}
                        className="underline underline-offset-2"
                        onClick={(event) => {
                            event.preventDefault();
                            onNavigate(href);
                        }}
                    >
                        {link[1]}
                    </a>
                );
            }

            if (/^https:\/\//.test(href)) {
                return (
                    <a
                        key={key}
                        href={href}
                        rel="noreferrer noopener"
                        target="_blank"
                        className="underline underline-offset-2"
                    >
                        {link[1]}
                    </a>
                );
            }

            return link[1];
        }

        return part;
    });
}

export function Markdown({
    source,
    idPrefix = '',
    onNavigate,
}: {
    source: string;
    /** Keeps heading ids unique when two articles are in the document. */
    idPrefix?: string;
    onNavigate: (target: string) => void;
}) {
    return (
        <div className="space-y-3 text-sm wrap-anywhere">
            {parseBlocks(source).map((block, index) => {
                const key = `b${index}`;

                if (block.kind === 'heading') {
                    const Tag = (block.level <= 2 ? 'h3' : 'h4') as 'h3' | 'h4';

                    return (
                        <Tag
                            key={key}
                            id={`${idPrefix}${block.id}`}
                            className="pt-2 text-sm font-semibold"
                        >
                            {block.text}
                        </Tag>
                    );
                }

                if (block.kind === 'list') {
                    const List = block.ordered ? 'ol' : 'ul';

                    return (
                        <List
                            key={key}
                            className={
                                block.ordered
                                    ? 'list-decimal space-y-1 ps-5'
                                    : 'list-disc space-y-1 ps-5'
                            }
                        >
                            {block.items.map((item, itemIndex) => (
                                <li key={`${key}-${itemIndex}`}>
                                    {inline(
                                        item,
                                        onNavigate,
                                        `${key}-${itemIndex}`,
                                    )}
                                </li>
                            ))}
                        </List>
                    );
                }

                return <p key={key}>{inline(block.text, onNavigate, key)}</p>;
            })}
        </div>
    );
}
