import { Link, router } from '@inertiajs/react';
import { CheckIcon, ChevronsUpDownIcon, PlusIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { ClientModule, ClientNavigationOption } from '@/types/navigation';

/**
 * The client, as the thing everything to its right is scoped by.
 *
 * A combobox rather than a menu of radio items. The list is every client the
 * viewer may enter, and that list only grows: a menu you scroll is a menu you
 * stop using once it passes a screenful, and the switcher is the one control
 * that has to keep working at any size. Typing narrows it; there is no separate
 * "all clients" screen to fall back to, because a second way to pick a client
 * is a second navigation hierarchy.
 *
 * Each option carries its own finished URLs, so switching never assembles a
 * path: the same module on the newly chosen client when that client serves it,
 * and Client Home when it does not. An operator on Invoices stays on Invoices;
 * a client whose portal has no invoices tab lands somewhere real instead of on
 * a 404 built in the browser.
 *
 * Deliberately not a `<select>`: a form control reads as a field the reader is
 * expected to fill in, and this is not input - it is where they already are.
 */
export function ClientCompanySwitcher({
    clients,
    currentId,
    module,
    onCreateClient,
}: {
    clients: ClientNavigationOption[];
    currentId: string | null;
    /** The module to stay on when the newly chosen client serves it. */
    module: ClientModule;
    /** Offered to a viewer who may add a client; absent for everyone else. */
    onCreateClient?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const container = useRef<HTMLDivElement>(null);
    const field = useRef<HTMLInputElement>(null);
    const listId = useId();

    const current = clients.find((client) => client.id === currentId) ?? null;

    useEffect(() => {
        if (!open) {
            return;
        }

        field.current?.focus();

        // Escape and an outside click both close, because this is a popover
        // over a page the reader can still see: leaving it open while they
        // click past it would cover the page they aimed at.
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        const onPointerDown = (event: MouseEvent) => {
            if (!container.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('mousedown', onPointerDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.removeEventListener('mousedown', onPointerDown);
        };
    }, [open]);

    const needle = query.trim().toLowerCase();
    const matches =
        needle === ''
            ? clients
            : clients.filter((client) =>
                  client.name.toLowerCase().includes(needle),
              );

    const choose = (client: ClientNavigationOption) => {
        setOpen(false);

        if (client.id === currentId) {
            return;
        }

        // The same module on the new client, or its home when that client's
        // route family has no such page. Falling back rather than dropping the
        // click keeps "changing client is one action" true from every screen.
        router.visit(client.destinations[module] ?? client.destinations.home);
    };

    return (
        // The floor lives on the wrapper, not on the button. Putting it on the
        // button let the button overflow a wrapper that had already shrunk, so
        // it painted over the section menu beside it rather than pushing it
        // along - the row measured as fitting while a control sat underneath
        // another one.
        <div
            className="relative max-w-56 min-w-20 shrink sm:min-w-0"
            ref={container}
        >
            <Button
                variant="secondary"
                size="sm"
                aria-label="Current client"
                aria-expanded={open}
                aria-haspopup="dialog"
                aria-controls={open ? listId : undefined}
                title={current?.name}
                className="w-full"
                onClick={() => {
                    // Cleared as the popover opens rather than in an effect
                    // reacting to it: a filter left over from last time hides
                    // the client the reader just came to switch away from.
                    setQuery('');
                    setOpen((value) => !value);
                }}
            >
                <span className="truncate font-semibold">
                    {current?.name ?? 'Select a client'}
                </span>
                <ChevronsUpDownIcon
                    aria-hidden="true"
                    className="size-3.5 shrink-0 opacity-60"
                />
            </Button>

            {open && (
                <div
                    id={listId}
                    className="absolute start-0 top-full z-50 mt-1 w-72 rounded-xl border border-border bg-popover p-1 text-popover-foreground shadow-lg"
                >
                    <Input
                        ref={field}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        aria-label="Filter clients"
                        placeholder="Filter clients"
                        className="h-8"
                    />

                    <ul className="mt-1 max-h-72 overflow-y-auto">
                        {matches.length === 0 && (
                            <li className="px-2 py-3 text-sm text-muted-foreground">
                                No clients match that.
                            </li>
                        )}
                        {matches.map((client) => (
                            <li key={client.id}>
                                <Link
                                    href={
                                        client.destinations[module] ??
                                        client.destinations.home
                                    }
                                    title={client.name}
                                    aria-current={
                                        client.id === currentId
                                            ? 'true'
                                            : undefined
                                    }
                                    onClick={(event) => {
                                        event.preventDefault();
                                        choose(client);
                                    }}
                                    className={cn(
                                        'flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm',
                                        'hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        client.id === currentId &&
                                            'font-medium',
                                    )}
                                >
                                    <CheckIcon
                                        aria-hidden="true"
                                        className={cn(
                                            'size-3.5 shrink-0',
                                            client.id === currentId
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    <span className="truncate">
                                        {client.name}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>

                    {/*
                     * Adding a client is the one thing a switcher is asked for
                     * that is not switching, so it sits below a rule rather
                     * than among the names - and only for someone who may do
                     * it. The action behind it authorizes on its own.
                     */}
                    {onCreateClient !== undefined && (
                        <>
                            <div
                                aria-hidden="true"
                                className="my-1 h-px bg-border"
                            />
                            <button
                                type="button"
                                onClick={() => {
                                    setOpen(false);
                                    onCreateClient();
                                }}
                                className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <PlusIcon
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                                Add client
                            </button>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
