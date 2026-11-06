import { HelpCircleIcon } from 'lucide-react';
import { setHelpOpen, useHelpOpen } from '@/components/help/help-store';
import { Button } from '@/components/ui/button';

/** The navbar's `?`: an icon button, with the shortcut in its name. */
export function HelpButton() {
    const open = useHelpOpen();

    return (
        <Button
            variant="ghost"
            size="icon-sm"
            aria-label="Help"
            aria-expanded={open}
            aria-keyshortcuts="Control+/ Meta+/"
            title="Help (Ctrl /)"
            data-test="help-button"
            onClick={() => setHelpOpen(!open)}
        >
            <HelpCircleIcon aria-hidden="true" className="size-4" />
        </Button>
    );
}
