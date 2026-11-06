import { useSyncExternalStore } from 'react';

/**
 * Whether the help panel is open.
 *
 * A module-level store rather than context: the `?` button in the navbar and
 * the panel beside the page are siblings under the shell, and the keyboard
 * shortcut is bound on the document. The button asks for the panel to open and
 * learns nothing about it.
 */
let open = false;
const listeners = new Set<() => void>();

export function setHelpOpen(next: boolean): void {
    if (open === next) {
        return;
    }

    open = next;
    listeners.forEach((listener) => listener());
}

export function toggleHelp(): void {
    setHelpOpen(!open);
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function useHelpOpen(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => open,
        () => false,
    );
}
