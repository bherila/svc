import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { PlanningPage } from '@/types/task-planning';

export function usePlanningMutation(refresh: () => Promise<void>) {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inFlight = useRef(false);
    const keys = useRef(new Map<string, string>());

    async function run(
        method: 'POST' | 'PATCH' | 'PUT' | 'DELETE',
        href: string,
        body: object,
        saved: () => void,
    ) {
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
        setPending(true);
        setError(null);
        const signature = JSON.stringify([method, href, body]);
        const key = keys.current.get(signature) ?? newIdempotencyKey();
        keys.current.set(signature, key);

        try {
            const result = await apiRequest(method, href, body, key);

            if (!result.ok) {
                setError(
                    result.status === 409
                        ? 'Planning changed since you started editing. Your choices are preserved. Reload planning before trying again.'
                        : result.message,
                );

                return;
            }

            keys.current.delete(signature);
            await refresh();
            saved();
        } catch {
            setError(
                'The change was saved, but current planning could not be refreshed. Reload planning before making another change.',
            );
        } finally {
            inFlight.current = false;
            setPending(false);
        }
    }

    async function reload(discardDraft: () => void) {
        if (inFlight.current) {
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
                'Planning could not be refreshed. Your choices are preserved. Try reloading again.',
            );
        } finally {
            inFlight.current = false;
            setPending(false);
        }
    }

    return { pending, error, run, reload, clearError: () => setError(null) };
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
