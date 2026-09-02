import { router } from '@inertiajs/react';
import {
    BuildingIcon,
    CheckSquareIcon,
    FolderIcon,
    ReceiptIcon,
    SearchIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import type { SearchResult, SearchResultKind } from '@/types/search';

/**
 * Jump anywhere, from anywhere, with one key.
 *
 * Matching happens on the server rather than over a list shipped to the
 * browser. That is not an optimisation - it is the only version of this that
 * can be trusted. A palette holding every client, project and invoice would
 * publish the whole workspace to whoever opened dev tools, and it would
 * publish it to a scoped member who is allowed to see four projects. The
 * endpoint answers per person, so what the palette can find is what its viewer
 * can already open.
 *
 * cmdk's own filtering is therefore switched off. It ranks the rows it was
 * given, and the rows here are already the answer to the query - re-filtering
 * them client-side would hide server matches whose relevance is not spelled
 * out in the title, such as an invoice found by number under a client named
 * something else.
 */

/** How long a pause in typing counts as "done typing". */
const DEBOUNCE_MS = 180;

const KIND_ORDER: readonly SearchResultKind[] = [
    'client',
    'project',
    'invoice',
    'task',
];

const KIND_HEADING: Record<SearchResultKind, string> = {
    client: 'Clients',
    project: 'Projects',
    invoice: 'Invoices',
    task: 'Tasks',
};

const KIND_ICON: Record<SearchResultKind, typeof BuildingIcon> = {
    client: BuildingIcon,
    project: FolderIcon,
    invoice: ReceiptIcon,
    task: CheckSquareIcon,
};

/**
 * Everyone currently able to open the palette.
 *
 * The palette is mounted beside the Inertia page rather than inside it, so a
 * trigger rendered by a layout is in a different React tree and cannot reach
 * its state through context or a prop. A module-level set is the smallest
 * thing that spans the two, and it spans them only in this direction: the
 * trigger asks for the palette to open and learns nothing about it.
 */
const openListeners = new Set<() => void>();

/** Opens the palette from anywhere, including outside its React tree. */
export function openCommandPalette(): void {
    openListeners.forEach((listener) => listener());
}

/**
 * The palette's visible affordance.
 *
 * A keyboard shortcut nobody is told about is a feature only its author has,
 * so the bar carries the button and the button carries the shortcut.
 */
export function CommandPaletteTrigger() {
    // Read once, lazily, rather than on every render or in an effect. Guarded
    // for the no-navigator case so the component can be rendered outside a
    // browser at all; the non-Mac spelling is the safe default because
    // Ctrl+K also works on a Mac.
    const [isMac] = useState(
        () =>
            typeof navigator !== 'undefined' &&
            /Mac|iPhone|iPad/.test(navigator.userAgent),
    );

    return (
        <button
            type="button"
            onClick={openCommandPalette}
            className="hidden h-7 shrink-0 items-center gap-2 rounded-lg border border-border bg-background px-2 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:flex"
        >
            <SearchIcon aria-hidden="true" className="size-3.5" />
            <span>Search</span>
            <kbd className="rounded border border-border px-1 font-sans text-xs">
                {isMac ? '\u2318K' : 'Ctrl K'}
            </kbd>
        </button>
    );
}

/**
 * Cmd+K on a Mac, Ctrl+K elsewhere.
 *
 * Bound on the document rather than on a wrapper, because the palette has to
 * open from a page it does not wrap. The handler stops the browser's own
 * Ctrl+K - the address bar's search shortcut in several browsers - only once
 * it has decided to act, so the key keeps working normally everywhere else.
 */
function useCommandPaletteShortcut(onToggle: () => void) {
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key !== 'k' && event.key !== 'K') {
                return;
            }

            if (!event.metaKey && !event.ctrlKey) {
                return;
            }

            event.preventDefault();
            onToggle();
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [onToggle]);
}

export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    // Whether a question is outstanding - from the keystroke, not from the
    // request. `isSearching` used to be set when the fetch went out, which
    // left a 180 ms window on the first keystroke where the term was
    // non-blank, no results had arrived and nothing was marked in flight: the
    // palette said "Nothing matched." about a search it had not yet made.
    const [isSearching, setIsSearching] = useState(false);
    // One place closes the palette, and closing is what forgets the search.
    // Done here rather than in an effect watching `open` so that reopening
    // cannot briefly show the answer to the previous question between the
    // render that opens it and the effect that would have cleared it.
    const close = () => {
        setOpen(false);
        setTerm('');
        setResults([]);
        setIsSearching(false);
    };
    const changeOpen = (next: boolean) => (next ? setOpen(true) : close());
    // Every in-flight request, so a slow early response cannot overwrite a
    // fast later one. Without this, typing "ac" then "acme" can leave the
    // "ac" results on screen under the word "acme" - the classic
    // search-as-you-type bug, and the one users read as "search is broken".
    const requestId = useRef(0);

    // The hook rebinds on every render, so this closure always sees the
    // current `open` - which is what lets the toggle route its close through
    // `close()` rather than flipping the flag and stranding the old results.
    useCommandPaletteShortcut(() => changeOpen(!open));

    useEffect(() => {
        const openFromTrigger = () => setOpen(true);

        openListeners.add(openFromTrigger);

        return () => {
            openListeners.delete(openFromTrigger);
        };
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        const query = term.trim();

        if (query === '') {
            return;
        }

        const id = ++requestId.current;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/search?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
                credentials: 'same-origin',
            })
                .then((response) =>
                    response.ok ? response.json() : { results: [] },
                )
                .then((payload: { results?: SearchResult[] }) => {
                    if (id !== requestId.current) {
                        return;
                    }

                    setResults(payload.results ?? []);
                    setIsSearching(false);
                })
                .catch(() => {
                    // An aborted request is the expected path on every
                    // keystroke, and a failed one leaves the previous results
                    // rather than blanking the list under someone mid-type.
                    if (id === requestId.current) {
                        setIsSearching(false);
                    }
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, term]);

    const select = (result: SearchResult) => {
        close();
        router.visit(result.href);
    };

    const trimmed = term.trim();
    // Only meaningful with more than one workspace; on a single one it would
    // be the same word on every row.
    const workspaces = new Set(results.map((result) => result.workspace));
    const showWorkspace = workspaces.size > 1;

    return (
        <CommandDialog
            open={open}
            onOpenChange={changeOpen}
            title="Search"
            description="Jump to a client, project, invoice or task."
            className="sm:max-w-xl"
            showCloseButton={false}
            commandProps={{ shouldFilter: false }}
        >
            <CommandInput
                placeholder="Search clients, projects, invoices and tasks…"
                value={term}
                onValueChange={(next) => {
                    setTerm(next);

                    // Emptying the box returns the palette to rest at once,
                    // rather than leaving the last matches under a blank
                    // query until some future response replaces them.
                    if (next.trim() === '') {
                        setResults([]);
                        setIsSearching(false);

                        return;
                    }

                    // Outstanding from here, through the debounce and the
                    // request, until an answer for this term lands.
                    setIsSearching(true);
                }}
            />
            <CommandList>
                {/*
                 * Three states, said apart. "Type to search" is not the same
                 * statement as "nothing matched", and showing the second while
                 * a request is still out tells the reader their search failed
                 * a moment before it succeeds.
                 */}
                {trimmed === '' && (
                    <CommandEmpty>
                        Type to search clients, projects, invoices and tasks.
                    </CommandEmpty>
                )}
                {trimmed !== '' && results.length === 0 && (
                    <CommandEmpty>
                        {isSearching ? 'Searching…' : 'Nothing matched.'}
                    </CommandEmpty>
                )}
                {KIND_ORDER.map((kind) => {
                    const rows = results.filter(
                        (result) => result.kind === kind,
                    );

                    if (rows.length === 0) {
                        return null;
                    }

                    const Icon = KIND_ICON[kind];

                    return (
                        <CommandGroup key={kind} heading={KIND_HEADING[kind]}>
                            {rows.map((result) => (
                                <CommandItem
                                    key={`${result.kind}:${result.id}`}
                                    value={`${result.kind}:${result.id}`}
                                    onSelect={() => select(result)}
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="text-muted-foreground"
                                    />
                                    <span className="truncate">
                                        {result.title}
                                    </span>
                                    {result.subtitle !== null && (
                                        <span className="truncate text-xs text-muted-foreground">
                                            {result.subtitle}
                                        </span>
                                    )}
                                    {showWorkspace && (
                                        <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                            {result.workspace}
                                        </span>
                                    )}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    );
                })}
            </CommandList>
        </CommandDialog>
    );
}
