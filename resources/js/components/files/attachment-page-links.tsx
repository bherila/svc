import { Link } from '@inertiajs/react';

type Props = {
    nextHref?: string | null;
    previousHref?: string | null;
};

export function AttachmentPageLinks({ nextHref, previousHref }: Props) {
    if (!nextHref && !previousHref) {
        return null;
    }

    return (
        <nav aria-label="File pages" className="flex flex-wrap gap-4 text-sm">
            {previousHref && (
                <Link
                    href={previousHref}
                    className="underline underline-offset-4"
                >
                    Newer files
                </Link>
            )}
            {nextHref && (
                <Link href={nextHref} className="underline underline-offset-4">
                    Older files
                </Link>
            )}
        </nav>
    );
}
