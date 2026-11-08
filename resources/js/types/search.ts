/**
 * One command palette row, as the server resolved it.
 *
 * The href is decided server-side because the destination depends on
 * authorization and on which screens exist - a task has no page of its own and
 * resolves to its client's Tasks tab. Rebuilding these paths here would be a
 * second copy of the routing rules in the one place nobody checks them.
 *
 * Mirrors `App\Support\Search\SearchResult::toPaletteArray()`.
 */

export type SearchResultKind =
    | 'client'
    | 'project'
    | 'invoice'
    | 'task'
    | 'note'
    | 'discussion'
    | 'comment'
    | 'history'
    | 'expense'
    | 'agreement'
    | 'proposal'
    | 'file'
    | 'time'
    | 'contact'
    | 'portal_user';

export type SearchResult = {
    kind: SearchResultKind;
    id: string;
    title: string;
    /** The parent that tells two same-named rows apart, when there is one. */
    subtitle: string | null;
    href: string;
    /** Shown only when the viewer belongs to more than one workspace. */
    workspace: string;
    /** The workspace's public id; how a recent item is filed. */
    workspace_id: string;
    /** The group this row appears under, worded by the server. */
    heading: string;
    /** Whether opening this row is remembered as a recent item. */
    recent: boolean;
};

/**
 * One command, already resolved to where it goes.
 *
 * The server only sends what this viewer may do, so there is no disabled state
 * and nothing here to interpret. Mirrors `App\Support\Search\PaletteAction`.
 */
export type PaletteAction = {
    id: string;
    label: string;
    href: string;
    /** Extra words the command answers to. */
    keywords: string[];
    /** Said beside the label when the command lands on a picker. */
    hint: string | null;
};

/** What the palette shows before anything is typed. `GET /palette`. */
export type PalettePayload = {
    /** Null for someone with no workspace to search. */
    audience: 'workspace' | 'portal' | null;
    workspace: { id: string; name: string } | null;
    actions: PaletteAction[];
    recents: SearchResult[];
};
