import { HelpCircleIcon } from 'lucide-react';
import { useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { glossaryDefinition } from '@/help/glossary';

/**
 * A `?` beside a word a new user may not know.
 *
 * It opens on click and on focus as well as on hover, so it works on touch and
 * from the keyboard - a tip that only hover can open does not exist on a phone.
 * The text comes from the glossary, or from `children` for a one-off. Escape,
 * blur and an outside press close it.
 */
export function TermTip({
    term,
    children,
}: {
    term: string;
    /** Overrides the glossary definition. */
    children?: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const text = children ?? glossaryDefinition(term);

    if (text === null || text === undefined) {
        return null;
    }

    return (
        <TooltipProvider>
            <Tooltip
                open={open}
                onOpenChange={(next, details) => {
                    // A press on the trigger is a click below, which opens it;
                    // honouring the press-to-close would flicker it shut on
                    // the very tap meant to show it.
                    if (details.reason === 'trigger-press') {
                        return;
                    }

                    setOpen(next);
                }}
            >
                <TooltipTrigger
                    render={
                        <button
                            type="button"
                            aria-label={`What is ${term}?`}
                            data-test="term-tip"
                            className="inline-flex size-5 items-center justify-center rounded-full align-middle text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            onClick={() => setOpen(true)}
                        />
                    }
                >
                    <HelpCircleIcon aria-hidden="true" className="size-3.5" />
                </TooltipTrigger>
                <TooltipContent role="tooltip">{text}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
