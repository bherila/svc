type HistoryGuard = {
    unknown: () => boolean;
    blocked: () => void;
    entry: { state: unknown; href: string };
};
const guards = new Set<HistoryGuard>();
let installed = false;

/** Install before Inertia initializes: window-target listeners run in registration order. */
export function installUnknownWriteHistoryGuard() {
    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;
    window.addEventListener('popstate', (event) => {
        const guard = [...guards].find((candidate) => candidate.unknown());

        if (!guard) {
            return;
        }

        event.stopImmediatePropagation();
        window.history.pushState(guard.entry.state, '', guard.entry.href);
        guard.blocked();
    });
}

/** Register only a predicate and the current browser entry; write bodies stay in their forms. */
export function registerUnknownWriteHistoryGuard(guard: HistoryGuard) {
    installUnknownWriteHistoryGuard();
    guards.add(guard);

    return () => {
        guards.delete(guard);
    };
}
