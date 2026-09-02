/**
 * One command palette row, as the server resolved it.
 *
 * The href is decided server-side because the destination depends on
 * authorization and on which screens exist - a task has no page of its own and
 * resolves to its client's Tasks tab. Rebuilding these paths here would be a
 * second copy of the routing rules in the one place nobody checks them.
 *
 * Mirrors `App\Support\Search\SearchResult::toArray()`.
 */

export type SearchResultKind = 'client' | 'project' | 'invoice' | 'task';

export type SearchResult = {
    kind: SearchResultKind;
    id: string;
    title: string;
    /** The parent that tells two same-named rows apart, when there is one. */
    subtitle: string | null;
    href: string;
    /** Shown only when the viewer belongs to more than one workspace. */
    workspace: string;
};
