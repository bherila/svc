import { Link } from '@inertiajs/react';
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
 */

const MODULES: readonly { key: ClientModule; label: string }[] = [
    { key: 'home', label: 'Client Home' },
    { key: 'invoices', label: 'Invoices' },
    { key: 'time', label: 'Time' },
    { key: 'expenses', label: 'Expenses' },
    { key: 'tasks', label: 'Tasks' },
];

export function ClientModuleTabs({
    active,
    destinations,
    className,
}: {
    active: ClientModule;
    destinations: ClientModuleDestinations;
    className?: string;
}) {
    return (
        <nav
            aria-label="Client sections"
            className={cn(
                'flex min-w-0 items-center gap-0.5 overflow-x-auto',
                className,
            )}
        >
            {MODULES.map((module) => {
                const href = destinations[module.key];

                if (href === null) {
                    return null;
                }

                return (
                    <Link
                        key={module.key}
                        href={href}
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
                );
            })}
        </nav>
    );
}
