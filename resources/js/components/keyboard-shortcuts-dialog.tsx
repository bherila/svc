import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/** Whether this is a Mac, so shortcuts are spelled the way the keyboard is. */
export function isMacPlatform(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad/.test(navigator.userAgent)
    );
}

type Shortcut = {
    /** Each inner list is one alternative; keys inside it are pressed together. */
    keys: string[][];
    label: string;
};

type ShortcutGroup = { heading: string; shortcuts: Shortcut[] };

function groups(mod: string): ShortcutGroup[] {
    return [
        {
            heading: 'Anywhere',
            shortcuts: [
                { keys: [[mod, 'K'], ['/']], label: 'Open the search palette' },
                { keys: [['?']], label: 'Show these shortcuts' },
            ],
        },
        {
            heading: 'In the search palette',
            shortcuts: [
                { keys: [['↑'], ['↓']], label: 'Move through the list' },
                { keys: [['Enter']], label: 'Open the highlighted item' },
                { keys: [['Esc']], label: 'Close' },
            ],
        },
    ];
}

/**
 * The keyboard shortcuts, as a dialog.
 *
 * A definition list, because each row is a term (what it does) and its
 * definition (the keys) - a screen reader announces them as pairs. The keys are
 * `kbd` elements so they read as keys rather than as stray letters.
 */
export function KeyboardShortcutsDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const mod = isMacPlatform() ? '⌘' : 'Ctrl';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="grid-cols-1 sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Keyboard shortcuts</DialogTitle>
                    <DialogDescription>
                        Single-key shortcuts are ignored while you are typing in
                        a field.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid grid-cols-1 gap-4">
                    {groups(mod).map((group) => (
                        <section
                            key={group.heading}
                            aria-label={group.heading}
                            className="grid grid-cols-1 gap-2"
                        >
                            <h3 className="text-xs font-medium text-muted-foreground">
                                {group.heading}
                            </h3>
                            <dl className="grid grid-cols-1 gap-2">
                                {group.shortcuts.map((shortcut) => (
                                    <div
                                        key={shortcut.label}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <dt className="min-w-0 wrap-anywhere">
                                            {shortcut.label}
                                        </dt>
                                        <dd className="flex shrink-0 items-center gap-1">
                                            {shortcut.keys.map(
                                                (alternative, index) => (
                                                    <span
                                                        key={alternative.join(
                                                            '+',
                                                        )}
                                                        className="flex items-center gap-1"
                                                    >
                                                        {index > 0 && (
                                                            <span className="text-xs text-muted-foreground">
                                                                or
                                                            </span>
                                                        )}
                                                        {alternative.map(
                                                            (key) => (
                                                                <kbd
                                                                    key={key}
                                                                    className="rounded border border-border bg-muted px-1.5 py-0.5 font-sans text-xs"
                                                                >
                                                                    {key}
                                                                </kbd>
                                                            ),
                                                        )}
                                                    </span>
                                                ),
                                            )}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
