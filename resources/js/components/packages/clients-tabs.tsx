import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { WorkspaceNavigation } from '@/types/navigation';

/**
 * The second-level row under Clients: "All clients" | "Packages" | "Sign-up requests".
 *
 * Packages sit beside the roster rather than adding a sixth top-level tab.
 * Both destinations are finished URLs from the shared navigation; with the
 * feature off (or for anyone who does not manage the workspace) `packages_href`
 * is null and the row is not rendered at all.
 */
export function ClientsTabs({
    active,
}: {
    active: 'clients' | 'packages' | 'requests';
}) {
    const navigation = usePage().props
        .workspaceNavigation as WorkspaceNavigation | null;
    const packages = navigation?.packages_href ?? null;
    const clients = navigation?.workspace_destinations?.clients ?? null;
    // Only the package pages are sent these; the roster shows two tabs.
    const props = usePage().props as {
        requests_url?: string | null;
        open_requests_count?: number | null;
    };
    const requests = props.requests_url ?? null;
    const open = props.open_requests_count ?? 0;

    if (packages === null || clients === null) {
        return null;
    }

    const items = [
        { key: 'clients', label: 'All clients', href: clients },
        { key: 'packages', label: 'Packages', href: packages },
        ...(requests === null
            ? []
            : [
                  {
                      key: 'requests',
                      label:
                          open > 0
                              ? `Sign-up requests · ${open}`
                              : 'Sign-up requests',
                      href: requests,
                  },
              ]),
    ] as const;

    return (
        <nav
            aria-label="Clients and packages"
            className="flex min-w-0 items-center gap-0.5 overflow-x-auto border-b border-border pb-2"
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
    );
}
