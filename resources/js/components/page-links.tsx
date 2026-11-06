import { Link } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';

/** Previous and next links for a server-paginated list; nothing when there is one page. */
export function PageLinks({
    label,
    nextHref,
    previousHref,
}: {
    label: string;
    nextHref: string | null;
    previousHref: string | null;
}) {
    if (nextHref === null && previousHref === null) {
        return null;
    }

    return (
        <nav
            aria-label={label}
            className="mt-4 flex flex-wrap justify-between gap-3"
        >
            {previousHref !== null && (
                <Link
                    href={previousHref}
                    className="inline-flex min-h-11 items-center gap-1 text-sm underline underline-offset-4"
                >
                    <ChevronLeftIcon aria-hidden="true" className="size-4" />
                    Previous
                </Link>
            )}
            {nextHref !== null && (
                <Link
                    href={nextHref}
                    className="ml-auto inline-flex min-h-11 items-center gap-1 text-sm underline underline-offset-4"
                >
                    Next
                    <ChevronRightIcon aria-hidden="true" className="size-4" />
                </Link>
            )}
        </nav>
    );
}
