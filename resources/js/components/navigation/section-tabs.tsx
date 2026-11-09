import { Link } from '@inertiajs/react';
import { ChevronDownIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type {
    ClientModule,
    ClientModuleDestinations,
    WorkspaceDestinations,
    WorkspaceSection,
} from '@/types/navigation';

/**
 * The tab strip, for both tiers of the navbar.
 *
 *     Workspace tier   Dashboard  Clients  Invoices  Time  Finance
 *                      (My work first, for members who do not run the workspace)
 *     Client tier      Overview  Agreements  Invoices  Time  Tasks  Files  Services (portal)
 *
 * One list per tier, defined here, rather than a set each page declares: pages
 * that each name their own tabs drift. A tab with no destination is not
 * rendered - that is how the portal shows only what it serves, how a member who
 * may not see Finance never meets the word, and why the strip is always exactly
 * the set of screens this viewer can open.
 *
 * Two presentations of the one list. Wide enough for the strip, it is a strip.
 * Narrower (below `lg` for the workspace tier, `xl` for the client tier), it collapses into a menu naming the section you are
 * in ("Dashboard"), because the row must not wrap and the alternative - a strip
 * squeezed to nothing - left a phone with no way to reach anything. Both are
 * rendered from the same items, so a tab added to a list appears in both.
 */

type Item = { key: string; label: string; short?: string; href: string };

const CLIENT_TABS: readonly {
    key: ClientModule;
    label: string;
}[] = [
    { key: 'home', label: 'Overview' },
    { key: 'agreements', label: 'Agreements' },
    { key: 'invoices', label: 'Invoices' },
    { key: 'time', label: 'Time' },
    { key: 'tasks', label: 'Tasks' },
    { key: 'files', label: 'Files' },
    { key: 'services', label: 'Services' },
];

/** Not tabs: the client's own menu holds them, and the phone menu follows the tabs with them. */
const CLIENT_MORE: readonly {
    key: ClientModule;
    label: string;
}[] = [
    { key: 'settings', label: 'Client settings' },
    { key: 'expenses', label: 'Expenses' },
    { key: 'notes', label: 'Notes' },
    { key: 'discussions', label: 'Discussions' },
];

const WORKSPACE_TABS: readonly {
    key: WorkspaceSection;
    label: string;
}[] = [
    { key: 'my_work', label: 'My work' },
    { key: 'submissions', label: 'Submit' },
    { key: 'timesheet', label: 'Timesheet' },
    { key: 'dashboard', label: 'Dashboard' },
    { key: 'clients', label: 'Clients' },
    { key: 'invoices', label: 'Invoices' },
    { key: 'time', label: 'Time' },
    { key: 'finance', label: 'Finance' },
];

// Whole class names, so Tailwind sees them.
const STRIP_FROM = { md: 'md:flex', lg: 'lg:flex', xl: 'xl:flex' } as const;
const MENU_UNTIL = {
    md: 'md:hidden',
    lg: 'lg:hidden',
    xl: 'xl:hidden',
} as const;

export function SectionTabs({
    items,
    more = [],
    active,
    currentLabel,
    label,
    collapseBelow = 'md',
    className,
}: {
    items: Item[];
    /**
     * Destinations that are not tabs. They are not in the strip - the client's
     * own menu holds them there - but a phone has no room for that menu, so
     * they follow the tabs in the narrow-width menu instead.
     */
    more?: Item[];
    active: string | null;
    /** What the narrow trigger says when the current page is not one of the tabs. */
    currentLabel?: string;
    /** Names the landmark, for example "Workspace sections". */
    label: string;
    /**
     * The width from which the strip is shown; narrower, the menu is. The
     * client tier has six tabs and a breadcrumb, so it needs more room.
     */
    collapseBelow?: 'md' | 'lg' | 'xl';
    className?: string;
}) {
    const strip = useRef<HTMLElement>(null);
    useEffect(() => {
        const nav = strip.current;

        if (!nav) {
            return;
        }

        const revealCurrent = () => {
            const selected = nav.querySelector('[aria-current="page"]');

            if (!selected || nav.clientWidth === 0) {
                return;
            }

            const bounds = nav.getBoundingClientRect();
            const item = selected.getBoundingClientRect();

            if (item.right > bounds.right) {
                nav.scrollLeft += item.right - bounds.right;
            } else if (item.left < bounds.left) {
                nav.scrollLeft -= bounds.left - item.left;
            }
        };
        revealCurrent();
        const observer = new ResizeObserver(revealCurrent);
        observer.observe(nav);

        return () => observer.disconnect();
    }, [active]);
    const current = items.find((item) => item.key === active) ?? null;
    const triggerText =
        current?.short ?? current?.label ?? currentLabel ?? 'Sections';

    if (items.length === 0) {
        return null;
    }

    return (
        <>
            {/*
             * The strip. `min-w-0` lets it give ground before the row would
             * grow, and it scrolls rather than wrapping.
             */}
            <nav
                ref={strip}
                aria-label={label}
                className={cn(
                    'hidden min-w-0 items-center gap-0.5 overflow-x-auto',
                    STRIP_FROM[collapseBelow],
                    className,
                )}
            >
                {items.map((item) => (
                    <Link
                        key={item.key}
                        href={item.href}
                        aria-current={item.key === active ? 'page' : undefined}
                        className={cn(
                            'rounded-lg px-3 py-1.5 text-sm whitespace-nowrap transition-colors',
                            'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            item.key === active
                                ? 'bg-accent font-medium text-accent-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>

            {/*
             * The same list on a phone. The trigger names the section rather
             * than saying "Menu", so the bar still answers "where am I" at a
             * glance - which is the job the strip was doing.
             */}
            <div className={cn('min-w-0 shrink-0', MENU_UNTIL[collapseBelow])}>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            // The name has to contain the visible text (WCAG
                            // 2.5.3), so it leads with the section and then
                            // says what the control is.
                            aria-label={`${triggerText}: ${label}`}
                        >
                            <span className="truncate">{triggerText}</span>
                            <ChevronDownIcon
                                aria-hidden="true"
                                className="size-3.5 shrink-0 opacity-60"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-48">
                        {items.map((item) => (
                            <DropdownMenuItem key={item.key} asChild>
                                <Link
                                    href={item.href}
                                    aria-current={
                                        item.key === active ? 'page' : undefined
                                    }
                                    className={cn(
                                        item.key === active && 'font-medium',
                                    )}
                                >
                                    {item.label}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                        {more.length > 0 && <DropdownMenuSeparator />}
                        {more.map((item) => (
                            <DropdownMenuItem key={item.key} asChild>
                                <Link
                                    href={item.href}
                                    aria-current={
                                        item.key === active ? 'page' : undefined
                                    }
                                    className={cn(
                                        item.key === active && 'font-medium',
                                    )}
                                >
                                    {item.label}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </>
    );
}

export function ClientModuleTabs({
    active,
    destinations,
    className,
}: {
    active: ClientModule;
    destinations: ClientModuleDestinations;
    className?: string;
}) {
    const items = CLIENT_TABS.flatMap((tab) => {
        const href = destinations[tab.key];

        return href === null ? [] : [{ ...tab, href }];
    });

    const more = CLIENT_MORE.flatMap((tab) => {
        const href = destinations[tab.key];

        return href === null ? [] : [{ ...tab, href }];
    });

    return (
        <SectionTabs
            items={items}
            more={more}
            active={active}
            collapseBelow="xl"
            currentLabel={CLIENT_MORE.find((tab) => tab.key === active)?.label}
            label="Client sections"
            className={className}
        />
    );
}

export function WorkspaceSectionTabs({
    active,
    destinations,
    className,
}: {
    active: WorkspaceSection | null;
    destinations: WorkspaceDestinations;
    className?: string;
}) {
    const items = WORKSPACE_TABS.flatMap((tab) => {
        const href = destinations[tab.key];

        return href === null ? [] : [{ ...tab, href }];
    });

    return (
        <SectionTabs
            items={items}
            active={active}
            label="Workspace sections"
            collapseBelow="lg"
            className={className}
        />
    );
}
