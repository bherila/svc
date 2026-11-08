import { router } from '@inertiajs/react';
import {
    ArrowLeftRightIcon,
    BuildingIcon,
    CheckCheckIcon,
    CheckSquareIcon,
    ClockIcon,
    CreditCardIcon,
    FileIcon,
    FileSignatureIcon,
    FileTextIcon,
    FolderIcon,
    HandshakeIcon,
    HistoryIcon,
    KeyboardIcon,
    ListTodoIcon,
    MessageSquareIcon,
    MessagesSquareIcon,
    PaperclipIcon,
    ReceiptIcon,
    SearchIcon,
    SettingsIcon,
    TrendingUpIcon,
    UserIcon,
    UsersIcon,
    WalletIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    isMacPlatform,
    KeyboardShortcutsDialog,
} from '@/components/keyboard-shortcuts-dialog';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { xsrfHeader } from '@/lib/api';
import { isDialogOpen, isTypingTarget } from '@/lib/keyboard';
import { cn } from '@/lib/utils';
import type {
    PaletteAction,
    PalettePayload,
    SearchResult,
    SearchResultKind,
} from '@/types/search';

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
 *
 * ## The one list that is filtered here
 *
 * Commands ("Log time", "Go to Payments") are matched in the browser, against
 * a list the server built for this viewer. That is safe for the reason the
 * records are not: the list is a handful of entries and holds only what the
 * viewer may already do, each a finished URL. Revenue, for instance, is simply
 * absent for anyone who is not an owner or admin.
 */

/** How long a pause in typing counts as "done typing". */
const DEBOUNCE_MS = 180;

const KIND_ICON: Record<SearchResultKind, typeof BuildingIcon> = {
    client: BuildingIcon,
    project: FolderIcon,
    invoice: ReceiptIcon,
    task: CheckSquareIcon,
    note: FileTextIcon,
    discussion: MessagesSquareIcon,
    comment: MessageSquareIcon,
    history: HistoryIcon,
    expense: WalletIcon,
    agreement: HandshakeIcon,
    proposal: FileSignatureIcon,
    file: PaperclipIcon,
    time: ClockIcon,
    contact: UserIcon,
    portal_user: UsersIcon,
};

const ACTION_ICON: Record<string, typeof BuildingIcon> = {
    log_time: ClockIcon,
    new_invoice: ReceiptIcon,
    new_expense: WalletIcon,
    payments: CreditCardIcon,
    pay: CreditCardIcon,
    billing: CreditCardIcon,
    revenue: TrendingUpIcon,
    approvals: CheckCheckIcon,
    my_work: ListTodoIcon,
    invoices: ReceiptIcon,
    settings: SettingsIcon,
    switch_workspace: ArrowLeftRightIcon,
};

const EMPTY_PALETTE: PalettePayload = {
    audience: null,
    workspace: null,
    actions: [],
    recents: [],
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
export function CommandPaletteTrigger({
    compact = false,
}: {
    /** Always the icon button, however wide the window: the row has no room. */
    compact?: boolean;
}) {
    // Read once, lazily, rather than on every render or in an effect. The
    // non-Mac spelling is the safe default because Ctrl+K also works on a Mac.
    const [isMac] = useState(isMacPlatform);

    return (
        <button
            type="button"
            onClick={openCommandPalette}
            // An icon button on a phone and a labelled field with its shortcut
            // from `lg` up. The name is the visible text (no aria-label that
            // would disagree with it); on a phone that text is screen-reader only.
            title={compact ? 'Search' : undefined}
            className={cn(
                'flex size-7 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                !compact &&
                    'lg:h-7 lg:w-auto lg:justify-start lg:gap-2 lg:border lg:border-border lg:bg-background lg:px-2 lg:text-sm',
            )}
        >
            <SearchIcon
                aria-hidden="true"
                className={cn('size-4', !compact && 'lg:size-3.5')}
            />
            <span className={cn('sr-only', !compact && 'lg:not-sr-only')}>
                Search
            </span>
            <kbd
                className={cn(
                    'hidden rounded border border-border px-1 font-sans text-xs',
                    !compact && 'lg:inline',
                )}
                aria-hidden="true"
            >
                {isMac ? '⌘K' : 'Ctrl K'}
            </kbd>
        </button>
    );
}

/**
 * The palette's keys.
 *
 * - Cmd+K / Ctrl+K toggles it. A chord types nothing, so it works from inside a
 *   field - except a rich-text editor, which claims it for "insert link".
 * - `/` opens it and `?` opens the shortcut sheet, but only when nobody is
 *   typing: pressed in a field they are characters, not commands. They also
 *   stand down for a held modifier, a key repeat, an IME composition, an event
 *   something else already handled, and any dialog already open over the page.
 *
 * Bound on the document rather than on a wrapper, because the palette has to
 * open from a page it does not wrap. `preventDefault` is called only once the
 * key has been claimed, so the browser's own Ctrl+K keeps working elsewhere.
 */
function useShortcuts(handlers: {
    toggle: () => void;
    open: () => void;
    showShortcuts: () => void;
}) {
    const { toggle, open, showShortcuts } = handlers;

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.defaultPrevented || event.isComposing) {
                return;
            }

            if (
                (event.key === 'k' || event.key === 'K') &&
                (event.metaKey || event.ctrlKey)
            ) {
                if (
                    event.target instanceof HTMLElement &&
                    event.target.isContentEditable
                ) {
                    return;
                }

                event.preventDefault();
                toggle();

                return;
            }

            if (
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                event.repeat ||
                isTypingTarget(event.target) ||
                isDialogOpen()
            ) {
                return;
            }

            if (event.key === '/') {
                event.preventDefault();
                open();
            } else if (event.key === '?') {
                event.preventDefault();
                showShortcuts();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [toggle, open, showShortcuts]);
}

/** Whether every word the viewer typed appears in the command's label or keywords. */
function matchesAction(
    action: { label: string; keywords: string[] },
    query: string,
): boolean {
    const haystack =
        `${action.label} ${action.keywords.join(' ')}`.toLowerCase();

    return query
        .toLowerCase()
        .split(/\s+/)
        .filter((word) => word !== '')
        .every((word) => haystack.includes(word));
}

/** Tells the server this row was opened, so it can come back as a recent item. */
function rememberRecent(result: SearchResult): void {
    if (!result.recent) {
        return;
    }

    // Best effort: a recent item is a convenience, and the navigation that
    // follows must not wait on it. `keepalive` lets it finish after the visit.
    void fetch('/palette/recents', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...xsrfHeader(),
        },
        credentials: 'same-origin',
        keepalive: true,
        body: JSON.stringify({
            workspace_id: result.workspace_id,
            kind: result.kind,
            id: result.id,
        }),
    }).catch(() => undefined);
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [shortcutsOpen, setShortcutsOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    // What the palette shows before anything is typed. Null until it arrives,
    // so "no commands" and "not loaded yet" are not the same statement.
    const [palette, setPalette] = useState<PalettePayload | null>(null);
    // Whether a question is outstanding - from the keystroke, not from the
    // request. `isSearching` used to be set when the fetch went out, which
    // left a 180 ms window on the first keystroke where the term was
    // non-blank, no results had arrived and nothing was marked in flight: the
    // palette said "Nothing matched." about a search it had not yet made.
    const [isSearching, setIsSearching] = useState(false);
    const [highlight, setHighlight] = useState('');
    // One place closes the palette, and closing is what forgets the search.
    // Done here rather than in an effect watching `open` so that reopening
    // cannot briefly show the answer to the previous question between the
    // render that opens it and the effect that would have cleared it.
    const close = () => {
        setOpen(false);
        setTerm('');
        setResults([]);
        setPalette(null);
        setIsSearching(false);
    };
    const changeOpen = (next: boolean) => (next ? setOpen(true) : close());
    // Every in-flight request, so a slow early response cannot overwrite a
    // fast later one. Without this, typing "ac" then "acme" can leave the
    // "ac" results on screen under the word "acme" - the classic
    // search-as-you-type bug, and the one users read as "search is broken".
    const requestId = useRef(0);

    // The hook rebinds on every render, so these closures always see the
    // current `open` - which is what lets the toggle route its close through
    // `close()` rather than flipping the flag and stranding the old results.
    useShortcuts({
        toggle: () => changeOpen(!open),
        open: () => setOpen(true),
        showShortcuts: () => setShortcutsOpen(true),
    });

    useEffect(() => {
        const openFromTrigger = () => setOpen(true);

        openListeners.add(openFromTrigger);

        return () => {
            openListeners.delete(openFromTrigger);
        };
    }, []);

    // Commands and recent items, asked for afresh each time it opens: the page
    // the viewer is on decides which client the commands are about, and a
    // recent item opened a minute ago should already be there.
    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        fetch(`/palette?path=${encodeURIComponent(window.location.pathname)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload: Partial<PalettePayload> | null) => {
                if (controller.signal.aborted) {
                    return;
                }

                setPalette({
                    ...EMPTY_PALETTE,
                    audience: payload?.audience ?? null,
                    workspace: payload?.workspace ?? null,
                    actions: Array.isArray(payload?.actions)
                        ? payload.actions
                        : [],
                    recents: Array.isArray(payload?.recents)
                        ? payload.recents
                        : [],
                });
            })
            .catch(() => {
                // The palette still searches without its commands.
                if (!controller.signal.aborted) {
                    setPalette(EMPTY_PALETTE);
                }
            });

        return () => controller.abort();
    }, [open]);

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

    const go = (href: string) => {
        close();
        router.visit(href);
    };

    const select = (result: SearchResult) => {
        rememberRecent(result);
        go(result.href);
    };

    const showShortcuts = () => {
        close();
        setShortcutsOpen(true);
    };

    const trimmed = term.trim();
    const isPortal = palette?.audience === 'portal';
    const recents = palette?.recents ?? [];

    // Commands answer a typed query locally; with nothing typed they all show.
    const actions = useMemo(
        () =>
            (palette?.actions ?? []).filter(
                (action) => trimmed === '' || matchesAction(action, trimmed),
            ),
        [palette, trimmed],
    );
    const shortcutsListed =
        trimmed === '' ||
        matchesAction(
            {
                label: 'Keyboard shortcuts',
                keywords: ['help', 'keys', 'hotkeys', 'keyboard'],
            },
            trimmed,
        );

    // Only meaningful with more than one workspace; on a single one it would
    // be the same word on every row.
    const showWorkspace =
        new Set([...results, ...recents].map((result) => result.workspace))
            .size > 1;

    // Groups in the order the server ranked them. The heading is the server's
    // word for the kind, so a kind added there arrives with its own label.
    const groups = useMemo(() => {
        const byHeading = new Map<string, SearchResult[]>();

        results.forEach((result) => {
            byHeading.set(result.heading, [
                ...(byHeading.get(result.heading) ?? []),
                result,
            ]);
        });

        return [...byHeading.entries()];
    }, [results]);

    // What a screen reader is told as the list changes. A sighted reader sees
    // the rows appear; without this nothing says the search finished or what
    // it found.
    const announcement = (() => {
        if (trimmed === '') {
            if (palette === null) {
                return '';
            }

            const commands = actions.length + 1;

            return `${plural(commands, 'command', 'commands')}${
                recents.length > 0
                    ? ` and ${plural(recents.length, 'recent item', 'recent items')}`
                    : ''
            } available. Type to search.`;
        }

        if (isSearching) {
            return 'Searching.';
        }

        const total =
            actions.length + (shortcutsListed ? 1 : 0) + results.length;

        return total === 0
            ? 'No results.'
            : `${plural(total, 'result', 'results')}.`;
    })();

    // The row that is highlighted when the list (re)appears: the first one on
    // screen. cmdk picks the first row that has mounted, and the commands arrive
    // after the dialog does, so without this the highlight sat on the last,
    // local row ("Keyboard shortcuts") and Enter opened that instead.
    const firstValue =
        actions.length > 0
            ? `action:${actions[0].id}`
            : shortcutsListed
              ? 'action:keyboard-shortcuts'
              : trimmed === '' && recents.length > 0
                ? `recent:${recents[0].kind}:${recents[0].id}`
                : results.length > 0
                  ? `${results[0].kind}:${results[0].id}`
                  : '';

    useEffect(() => {
        setHighlight(firstValue);
    }, [firstValue, open]);

    const noMatches =
        trimmed !== '' &&
        results.length === 0 &&
        actions.length === 0 &&
        !shortcutsListed;

    return (
        <>
            <CommandDialog
                open={open}
                onOpenChange={changeOpen}
                title="Search"
                description={
                    isPortal
                        ? 'Jump to an invoice, project or file, or run a command.'
                        : 'Jump to a client, project, invoice or file, or run a command.'
                }
                className="grid-cols-1 sm:max-w-xl"
                showCloseButton={false}
                commandProps={{
                    shouldFilter: false,
                    label: 'Search and commands',
                    value: highlight,
                    onValueChange: setHighlight,
                }}
            >
                <CommandInput
                    placeholder={
                        isPortal
                            ? 'Search your invoices, projects and files…'
                            : 'Search clients, projects, invoices and more…'
                    }
                    value={term}
                    onValueChange={(next: string) => {
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
                <div
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                    className="sr-only"
                >
                    {announcement}
                </div>
                <CommandList aria-busy={isSearching}>
                    {/*
                     * Said apart: "type to search" is not "nothing matched",
                     * and showing the second while a request is still out tells
                     * the reader their search failed a moment before it
                     * succeeds.
                     */}
                    {noMatches && (
                        <CommandEmpty>
                            {isSearching ? 'Searching…' : 'Nothing matched.'}
                        </CommandEmpty>
                    )}
                    {(actions.length > 0 || shortcutsListed) && (
                        <CommandGroup heading="Commands">
                            {actions.map((action) => (
                                <ActionRow
                                    key={action.id}
                                    action={action}
                                    onSelect={() => go(action.href)}
                                />
                            ))}
                            {shortcutsListed && (
                                <CommandItem
                                    value="action:keyboard-shortcuts"
                                    onSelect={showShortcuts}
                                >
                                    <KeyboardIcon
                                        aria-hidden="true"
                                        className="text-muted-foreground"
                                    />
                                    <span className="min-w-0 truncate">
                                        Keyboard shortcuts
                                    </span>
                                    <kbd
                                        aria-hidden="true"
                                        className="ml-auto shrink-0 rounded border border-border px-1 font-sans text-xs text-muted-foreground in-data-[selected=true]:text-accent-foreground"
                                    >
                                        ?
                                    </kbd>
                                </CommandItem>
                            )}
                        </CommandGroup>
                    )}
                    {trimmed === '' && recents.length > 0 && (
                        <CommandGroup heading="Recent">
                            {recents.map((result) => (
                                <ResultRow
                                    key={`recent:${result.kind}:${result.id}`}
                                    value={`recent:${result.kind}:${result.id}`}
                                    result={result}
                                    showWorkspace={showWorkspace}
                                    onSelect={() => select(result)}
                                />
                            ))}
                        </CommandGroup>
                    )}
                    {groups.map(([heading, rows]) => (
                        <CommandGroup key={heading} heading={heading}>
                            {rows.map((result) => (
                                <ResultRow
                                    key={`${result.kind}:${result.id}`}
                                    value={`${result.kind}:${result.id}`}
                                    result={result}
                                    showWorkspace={showWorkspace}
                                    onSelect={() => select(result)}
                                />
                            ))}
                        </CommandGroup>
                    ))}
                </CommandList>
                <div className="flex items-center justify-between gap-3 border-t px-3 py-2 text-xs text-muted-foreground">
                    <p className="min-w-0 wrap-anywhere">
                        {trimmed === ''
                            ? isPortal
                                ? 'Type to search your invoices, projects and files.'
                                : 'Type to search clients, projects, invoices, files and more.'
                            : 'Up and down to move, Enter to open, Esc to close.'}
                    </p>
                    <button
                        type="button"
                        onClick={showShortcuts}
                        className="shrink-0 rounded px-1.5 py-0.5 hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        Shortcuts
                    </button>
                </div>
            </CommandDialog>
            <KeyboardShortcutsDialog
                open={shortcutsOpen}
                onOpenChange={setShortcutsOpen}
            />
        </>
    );
}

function ActionRow({
    action,
    onSelect,
}: {
    action: PaletteAction;
    onSelect: () => void;
}) {
    const Icon = ACTION_ICON[action.id] ?? ArrowLeftRightIcon;

    return (
        <CommandItem value={`action:${action.id}`} onSelect={onSelect}>
            <Icon aria-hidden="true" className="text-muted-foreground" />
            <span className="min-w-0 truncate">{action.label}</span>
            {action.hint !== null && (
                <span className="min-w-0 truncate text-xs text-muted-foreground in-data-[selected=true]:text-accent-foreground">
                    {action.hint}
                </span>
            )}
        </CommandItem>
    );
}

function ResultRow({
    result,
    value,
    showWorkspace,
    onSelect,
}: {
    result: SearchResult;
    value: string;
    showWorkspace: boolean;
    onSelect: () => void;
}) {
    const Icon = KIND_ICON[result.kind] ?? FileIcon;

    return (
        <CommandItem value={value} onSelect={onSelect}>
            <Icon aria-hidden="true" className="text-muted-foreground" />
            {/* The row is chrome and the full value is one click away, so an
                identity string may truncate here; `title` carries the rest. */}
            <span className="min-w-0 truncate" title={result.title}>
                {result.title}
            </span>
            {result.subtitle !== null && (
                <span
                    className="min-w-0 truncate text-xs text-muted-foreground in-data-[selected=true]:text-accent-foreground"
                    title={result.subtitle}
                >
                    {result.subtitle}
                </span>
            )}
            {showWorkspace && (
                <span className="ml-auto shrink-0 text-xs text-muted-foreground in-data-[selected=true]:text-accent-foreground">
                    {result.workspace}
                </span>
            )}
        </CommandItem>
    );
}
