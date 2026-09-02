import { Link } from '@inertiajs/react';
import { ChevronDownIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type {
    ClientModule,
    ClientModuleDestinations,
} from '@/types/navigation';

/**
 * The modules of the client named to the left, in one fixed order.
 *
 * One list, defined here, rather than a set each page declares: pages that each
 * name their own tabs drift, and the strip would differ depending on which tab
 * the reader is standing on. The order is the order an operator reads a client
 * - where it stands, what it owes, what was worked, what was spent, what is
 * left to do - not the order the routes were built.
 *
 * A module with no destination is not rendered. That is how the portal shows
 * only the modules it serves, and how Expenses stays out of the bar until the
 * expense record exists (#75): the tab strip is always exactly the set of
 * screens this viewer can open.
 *
 * Two presentations of that one list. Wide enough for the strip, it is a strip.
 * Narrower, it collapses into a menu naming the section you are in - because
 * the row must not wrap, and the alternative the strip fell back to was worse
 * than wrapping: squeezed between the switcher and the account menu it
 * collapsed to nothing at all, and a phone had no way to reach Invoices, Time
 * or Tasks. Both are rendered from `MODULES`, so a module added here appears in
 * whichever one the reader is looking at.
 */

const MODULES: readonly {
    key: ClientModule;
    label: string;
    /**
     * What the narrow-width trigger says. Only Home differs: the strip needs
     * "Client Home" to distinguish it from the workspace, and the trigger sits
     * beside the client's own name, where the word "Client" is already said.
     */
    short?: string;
}[] = [
    { key: 'home', label: 'Client Home', short: 'Home' },
    { key: 'invoices', label: 'Invoices' },
    { key: 'time', label: 'Time' },
    { key: 'expenses', label: 'Expenses' },
    { key: 'tasks', label: 'Tasks' },
];

type AvailableModule = {
    key: ClientModule;
    label: string;
    short?: string;
    href: string;
};

function availableModules(
    destinations: ClientModuleDestinations,
): AvailableModule[] {
    return MODULES.flatMap((module) => {
        const href = destinations[module.key];

        return href === null ? [] : [{ ...module, href }];
    });
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
    const modules = availableModules(destinations);
    const current = modules.find((module) => module.key === active) ?? null;

    return (
        <>
            {/*
             * The strip. `min-w-0` lets it give ground to the switcher before
             * the row would grow, and it scrolls rather than wrapping.
             */}
            <nav
                aria-label="Client sections"
                className={cn(
                    'hidden min-w-0 items-center gap-0.5 overflow-x-auto sm:flex',
                    className,
                )}
            >
                {modules.map((module) => (
                    <Link
                        key={module.key}
                        href={module.href}
                        aria-current={
                            module.key === active ? 'page' : undefined
                        }
                        className={cn(
                            'rounded-lg px-3 py-1.5 text-sm whitespace-nowrap transition-colors',
                            'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            module.key === active
                                ? 'bg-accent font-medium text-accent-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        {module.label}
                    </Link>
                ))}
            </nav>

            {/*
             * The same list on a phone. The trigger names the section rather
             * than saying "Menu", so the bar still answers "where am I" at a
             * glance - which is the job the strip was doing.
             */}
            <div className="shrink-0 sm:hidden">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Client sections"
                        >
                            <span className="truncate">
                                {current?.short ?? current?.label ?? 'Sections'}
                            </span>
                            <ChevronDownIcon
                                aria-hidden="true"
                                className="size-3.5 shrink-0 opacity-60"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-48">
                        {modules.map((module) => (
                            <DropdownMenuItem key={module.key} asChild>
                                <Link
                                    href={module.href}
                                    aria-current={
                                        module.key === active
                                            ? 'page'
                                            : undefined
                                    }
                                    className={cn(
                                        module.key === active && 'font-medium',
                                    )}
                                >
                                    {module.label}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </>
    );
}
