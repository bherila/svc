import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SkipLink } from '@/components/skip-link';
import { useAppearance } from '@/lib/appearance';
import { cn } from '@/lib/utils';

/**
 * The chrome of the public package pages: the workspace's name, the way back to
 * its catalog and a Sign in link for existing clients.
 *
 * Not WorkspaceShell - a visitor is no member of anything. It also loads nothing
 * from anywhere else: no script, font or image (PUB-7), so the name is text and
 * the whole page is served by SVC under its own content security policy.
 */
export function PublicLayout({
    workspaceName,
    catalogUrl,
    signInUrl,
    children,
}: {
    workspaceName: string;
    /** Null on the catalog itself, which is where the link would lead. */
    catalogUrl: string | null;
    signInUrl: string;
    children: ReactNode;
}) {
    // No control here, but this is what keeps the page following the viewer's
    // light or dark setting while it is open, as the signed-in screens do.
    useAppearance();

    const link =
        'rounded-lg px-3 py-1.5 text-sm whitespace-nowrap text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

    return (
        <div
            className="min-h-screen bg-background text-foreground"
            data-appearance-bridge
        >
            <SkipLink />
            <header className="border-b border-border">
                <div className="mx-auto flex h-12 w-full max-w-4xl items-center justify-between gap-3 px-4 sm:px-6">
                    <p className="min-w-0 text-sm font-semibold wrap-anywhere">
                        {workspaceName}
                    </p>
                    <nav
                        aria-label="Public pages"
                        className="flex shrink-0 items-center gap-1"
                    >
                        {catalogUrl !== null && (
                            <Link href={catalogUrl} className={link}>
                                All services
                            </Link>
                        )}
                        <Link href={signInUrl} className={cn(link)}>
                            Sign in
                        </Link>
                    </nav>
                </div>
            </header>
            <main className="mx-auto grid w-full max-w-4xl grid-cols-1 gap-6 px-4 py-8 sm:px-6">
                {children}
            </main>
        </div>
    );
}
