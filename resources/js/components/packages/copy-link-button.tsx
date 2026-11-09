import { CheckIcon, LinkIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

/**
 * Copies a finished public URL the server sent. The visible text stays "Copy
 * link" (the accessible name leads with it, WCAG 2.5.3); `subject` says which
 * link, for a table with one per row.
 */
export function CopyLinkButton({
    url,
    subject,
    size = 'sm',
}: {
    url: string;
    subject: string;
    size?: 'sm' | 'default';
}) {
    const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setState('copied');
            window.setTimeout(() => setState('idle'), 2500);
        } catch {
            setState('failed');
        }
    };

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <Button
                type="button"
                variant="outline"
                size={size}
                className="min-h-11 md:min-h-0"
                aria-label={`Copy link: ${subject}`}
                onClick={() => void copy()}
            >
                {state === 'copied' ? (
                    <CheckIcon aria-hidden />
                ) : (
                    <LinkIcon aria-hidden />
                )}
                Copy link
            </Button>
            <span role="status" className="text-xs text-muted-foreground">
                {state === 'copied' && 'Copied'}
                {state === 'failed' && 'Could not copy; select the link instead'}
            </span>
        </span>
    );
}
