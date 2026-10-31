import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUnknownWriteGuard } from '@/hooks/use-unknown-write-guard';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { PlanningPage } from '@/types/task-planning';

export function usePlanningMutation(
    refresh: () => Promise<void>,
    noun = 'Planning',
) {
    const [pending, setPending] = useState(false);
    const [unresolved, setUnresolved] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inFlight = useRef(false);
    const attempt = useRef<{
        method: 'POST' | 'PATCH' | 'PUT' | 'DELETE';
        href: string;
        body: object;
        key: string;
        accepted: boolean;
        uncertain: boolean;
        saved?: () => void;
    } | null>(null);

    useUnknownWriteGuard(
        () => attempt.current !== null && !attempt.current.accepted,
        () => setError('Check the original change before leaving this page.'),
    );

    async function retry() {
        const original = attempt.current;

        if (inFlight.current || original === null) {
            return;
        }

        inFlight.current = true;
        setPending(true);
        setError(null);

        try {
            if (!original.accepted) {
                const result = await apiRequest(
                    original.method,
                    original.href,
                    original.body,
                    original.key,
                );

                if (!result.ok) {
                    const uncertain =
                        original.uncertain ||
                        result.status === 0 ||
                        result.status === 408 ||
                        result.status >= 500;
                    original.uncertain = uncertain;
                    setUnresolved(uncertain);
                    setError(
                        result.status === 409 &&
                            !uncertain &&
                            noun === 'Planning'
                            ? `${noun} changed since you started editing. Your choices are preserved. Reload ${noun.toLowerCase()} before trying again.`
                            : result.message,
                    );

                    if (!uncertain) {
                        attempt.current = null;
                    }

                    return;
                }

                original.accepted = true;
            }

            await refresh();
            original.saved?.();
            attempt.current = null;
            setUnresolved(false);
        } catch {
            original.uncertain = true;
            setUnresolved(true);
            setError(
                original.accepted
                    ? `The change was saved, but current ${noun.toLowerCase()} could not be refreshed. Check the original change to refresh it.`
                    : 'The outcome could not be confirmed. Check the original change before editing.',
            );
        } finally {
            inFlight.current = false;
            setPending(false);
        }
    }

    async function run(
        method: 'POST' | 'PATCH' | 'PUT' | 'DELETE',
        href: string,
        body: object,
        saved?: () => void,
    ) {
        if (inFlight.current) {
            return;
        }

        attempt.current ??= {
            method,
            href,
            body: structuredClone(body),
            key: newIdempotencyKey(),
            accepted: false,
            uncertain: false,
            saved,
        };
        await retry();
    }

    async function reload(discardDraft: () => void) {
        if (inFlight.current || attempt.current !== null) {
            return;
        }

        inFlight.current = true;
        setPending(true);

        try {
            await refresh();
            discardDraft();
            setError(null);
        } catch {
            setError(
                `${noun} could not be refreshed. Your choices are preserved. Try reloading again.`,
            );
        } finally {
            inFlight.current = false;
            setPending(false);
        }
    }

    return {
        pending,
        unresolved,
        locked: pending || unresolved,
        error,
        run,
        retry,
        reload,
        clearError: () => {
            if (attempt.current === null) {
                setError(null);
            }
        },
    };
}

export function PlanningMutationError({
    mutation,
}: {
    mutation: ReturnType<typeof usePlanningMutation>;
}) {
    return mutation.error ? (
        <div className="grid min-w-0 grid-cols-1 gap-2">
            <p role="alert" className="text-sm wrap-anywhere text-destructive">
                {mutation.error}
            </p>
            {mutation.unresolved && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={mutation.pending}
                    onClick={() => void mutation.retry()}
                >
                    Check original change
                </Button>
            )}
        </div>
    ) : null;
}

/** Seeded empty pages are authoritative; only typing or explicit continuation fetches. */
export function PlanningPicker<T extends { id: string }>({
    name,
    initial,
    optionsHref,
    selected,
    onChange,
    display,
    limit,
    disabled = false,
}: {
    name: string;
    initial: PlanningPage<T>;
    optionsHref: string;
    selected: T[];
    onChange: (rows: T[]) => void;
    display: (row: T) => string;
    limit: number;
    disabled?: boolean;
}) {
    const id = useId();
    const [search, setSearch] = useState('');
    const [lookup, setLookup] = useState<{
        search: string;
        page: PlanningPage<T>;
    } | null>(null);
    const [lookupError, setLookupError] = useState<{
        search: string;
        message: string;
    } | null>(null);
    const [loadingSearch, setLoadingSearch] = useState<string | null>(null);
    const loading = loadingSearch === search;
    const error = lookupError?.search === search ? lookupError.message : null;
    const continuation = useRef<AbortController | null>(null);
    const page =
        lookup?.search === search
            ? lookup.page
            : search === ''
              ? initial
              : { data: [], next_href: null, previous_href: null };

    useEffect(() => {
        continuation.current?.abort();

        if (search === '') {
            return;
        }

        const abort = new AbortController();
        const timer = setTimeout(async () => {
            setLoadingSearch(search);
            setLookupError(null);

            try {
                const url = new URL(optionsHref, window.location.origin);
                url.searchParams.set('q', search);
                const response = await fetch(url, {
                    signal: abort.signal,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('lookup');
                }

                const result: PlanningPage<T> = await response.json();

                if (!abort.signal.aborted) {
                    setLookup({ search, page: result });
                }
            } catch {
                if (!abort.signal.aborted) {
                    setLookupError({
                        search,
                        message:
                            'Options could not be loaded. Try another search.',
                    });
                }
            } finally {
                if (!abort.signal.aborted) {
                    setLoadingSearch(null);
                }
            }
        }, 200);

        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [search, optionsHref]);
    useEffect(() => () => continuation.current?.abort(), []);

    async function more() {
        if (!page.next_href || loading) {
            return;
        }

        continuation.current?.abort();
        const abort = new AbortController();
        continuation.current = abort;
        setLoadingSearch(search);
        setLookupError(null);

        try {
            const response = await fetch(page.next_href, {
                signal: abort.signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('lookup');
            }

            const next: PlanningPage<T> = await response.json();

            if (!abort.signal.aborted) {
                setLookup({
                    search,
                    page: {
                        ...next,
                        data: [
                            ...new Map(
                                [...page.data, ...next.data].map((row) => [
                                    row.id,
                                    row,
                                ]),
                            ).values(),
                        ],
                    },
                });
            }
        } catch {
            if (!abort.signal.aborted) {
                setLookupError({
                    search,
                    message: 'More options could not be loaded. Try again.',
                });
            }
        } finally {
            if (!abort.signal.aborted) {
                setLoadingSearch(null);
            }
        }
    }

    return (
        <div className="grid min-w-0 grid-cols-1 gap-3">
            <div className="flex min-w-0 flex-wrap gap-2">
                {selected.map((row) => (
                    <Button
                        key={row.id}
                        type="button"
                        variant="outline"
                        disabled={disabled}
                        className="h-auto max-w-full min-w-0 wrap-anywhere whitespace-normal"
                        aria-label={`Remove ${display(row)}`}
                        onClick={() =>
                            onChange(
                                selected.filter((item) => item.id !== row.id),
                            )
                        }
                    >
                        {display(row)} ×
                    </Button>
                ))}
            </div>
            <Label htmlFor={id}>Find {name.toLowerCase()}</Label>
            <Input
                id={id}
                type="search"
                value={search}
                maxLength={100}
                disabled={disabled}
                onChange={(event) => {
                    setSearch(event.target.value);
                    setLookupError(null);
                }}
            />
            <div
                className="grid max-h-56 min-w-0 grid-cols-1 gap-2 overflow-y-auto rounded-lg border p-3"
                aria-busy={loading}
            >
                {page.data.map((row) => {
                    const checked = selected.some((item) => item.id === row.id);

                    return (
                        <label
                            key={row.id}
                            className="flex min-w-0 items-start gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                checked={checked}
                                disabled={
                                    disabled ||
                                    (!checked && selected.length >= limit)
                                }
                                onChange={() =>
                                    onChange(
                                        checked
                                            ? selected.filter(
                                                  (item) => item.id !== row.id,
                                              )
                                            : [...selected, row],
                                    )
                                }
                            />
                            <span className="min-w-0 wrap-anywhere">
                                {display(row)}
                            </span>
                        </label>
                    );
                })}
                {page.data.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No matching {name.toLowerCase()} loaded.
                    </p>
                )}
            </div>
            {page.next_href && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={loading || disabled}
                    onClick={() => void more()}
                >
                    Load more {name.toLowerCase()}
                </Button>
            )}
            {error && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {error}
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                {selected.length} of {limit} selected.
            </p>
        </div>
    );
}
