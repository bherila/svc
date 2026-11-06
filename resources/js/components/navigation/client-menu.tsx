import { Link } from '@inertiajs/react';
import { MoreHorizontalIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ClientModuleDestinations } from '@/types/navigation';

const MORE: readonly {
    key: 'settings' | 'expenses' | 'notes' | 'discussions';
    label: string;
}[] = [
    { key: 'settings', label: 'Client settings' },
    { key: 'expenses', label: 'Expenses' },
    { key: 'notes', label: 'Notes' },
    { key: 'discussions', label: 'Discussions' },
];

/**
 * The overflow menu on the client's name.
 *
 * Holds what belongs to the client but is not one of the six tabs: its settings
 * and the modules the tab strip no longer has room for. Rendered only when the
 * viewer has at least one of them, from the same finished URLs as the tabs.
 */
export function ClientMenu({
    clientName,
    destinations,
}: {
    clientName: string;
    destinations: ClientModuleDestinations;
}) {
    const items = MORE.flatMap((item) => {
        const href = destinations[item.key];

        return href === null ? [] : [{ ...item, href }];
    });

    if (items.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`More for ${clientName}`}
                    data-test="client-menu"
                    className="hidden shrink-0 xl:inline-flex"
                >
                    <MoreHorizontalIcon aria-hidden="true" className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
                {items.map((item) => (
                    <DropdownMenuItem key={item.key} asChild>
                        <Link href={item.href}>{item.label}</Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
