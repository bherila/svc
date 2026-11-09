import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';

/** First and next page links for a cursor-paged list; the server sends both URLs. */
export function PageLinks({
    label,
    firstPageUrl,
    nextPageUrl,
    className,
}: {
    label: string;
    firstPageUrl: string | null;
    nextPageUrl: string | null;
    className?: string;
}) {
    if (firstPageUrl === null && nextPageUrl === null) {
        return null;
    }

    return (
        <nav
            aria-label={label}
            className={cn('flex flex-wrap gap-4 text-sm', className)}
        >
            {firstPageUrl !== null && (
                <Link href={firstPageUrl}>First page</Link>
            )}
            {nextPageUrl !== null && <Link href={nextPageUrl}>Next page</Link>}
        </nav>
    );
}
