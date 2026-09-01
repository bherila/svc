import { useSyncExternalStore } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const storageKey = 'svc:appearance';
const serverAppearance: Appearance = 'system';
const listeners = new Set<() => void>();

let appearance: Appearance | undefined;
let systemMedia: MediaQueryList | null = null;
let systemMediaListener: (() => void) | null = null;
let storageListener: ((event: StorageEvent) => void) | null = null;

export function isAppearance(value: string | null): value is Appearance {
    return value === 'light' || value === 'dark' || value === 'system';
}

function readStoredAppearance(): Appearance {
    if (typeof window === 'undefined') {
        return serverAppearance;
    }

    try {
        const stored = window.localStorage.getItem(storageKey);

        return isAppearance(stored) ? stored : serverAppearance;
    } catch {
        return serverAppearance;
    }
}

function writeStoredAppearance(next: Appearance): void {
    try {
        window.localStorage.setItem(storageKey, next);
    } catch {
        // Privacy modes and hardened browsers may deny storage. The in-memory
        // preference still applies for this page rather than crashing render.
    }
}

function systemPrefersDark(): boolean {
    try {
        return window.matchMedia('(prefers-color-scheme: dark)').matches;
    } catch {
        return false;
    }
}

function applyAppearance(next: Appearance): void {
    if (typeof document === 'undefined' || typeof window === 'undefined') {
        return;
    }

    const isDark =
        next === 'dark' || (next === 'system' && systemPrefersDark());

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
}

function stopSystemListener(): void {
    if (systemMedia !== null && systemMediaListener !== null) {
        systemMedia.removeEventListener('change', systemMediaListener);
    }

    systemMedia = null;
    systemMediaListener = null;
}

function stopStorageListener(): void {
    if (typeof window !== 'undefined' && storageListener !== null) {
        window.removeEventListener('storage', storageListener);
    }

    storageListener = null;
}

function syncStorageListener(): void {
    if (typeof window === 'undefined' || listeners.size === 0) {
        stopStorageListener();

        return;
    }

    if (storageListener !== null) {
        return;
    }

    storageListener = (event): void => {
        if (event.key !== storageKey && event.key !== null) {
            return;
        }

        const next = isAppearance(event.newValue)
            ? event.newValue
            : serverAppearance;
        appearance = next;
        applyAppearance(next);
        syncSystemListener();
        listeners.forEach((listener) => listener());
    };
    window.addEventListener('storage', storageListener);
}

function syncSystemListener(): void {
    const shouldListen =
        typeof window !== 'undefined' &&
        listeners.size > 0 &&
        getAppearanceSnapshot() === 'system';

    if (!shouldListen) {
        stopSystemListener();

        return;
    }

    if (systemMedia !== null) {
        return;
    }

    try {
        systemMedia = window.matchMedia('(prefers-color-scheme: dark)');
        systemMediaListener = () => applyAppearance('system');
        systemMedia.addEventListener('change', systemMediaListener);
    } catch {
        stopSystemListener();
    }
}

function getAppearanceSnapshot(): Appearance {
    appearance ??= readStoredAppearance();

    return appearance;
}

function getServerAppearanceSnapshot(): Appearance {
    return serverAppearance;
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    applyAppearance(getAppearanceSnapshot());
    syncSystemListener();
    syncStorageListener();

    return () => {
        listeners.delete(listener);

        if (listeners.size === 0) {
            stopSystemListener();
            stopStorageListener();
        }
    };
}

export function setAppearance(next: Appearance): void {
    appearance = next;
    writeStoredAppearance(next);
    applyAppearance(next);
    syncSystemListener();
    listeners.forEach((listener) => listener());
}

export function useAppearance(): readonly [
    Appearance,
    (next: Appearance) => void,
] {
    return [
        useSyncExternalStore(
            subscribe,
            getAppearanceSnapshot,
            getServerAppearanceSnapshot,
        ),
        setAppearance,
    ];
}

/** Reset module state only after mounted consumers have been cleaned up. */
export function resetAppearanceStoreForTests(): void {
    stopSystemListener();
    stopStorageListener();
    appearance = undefined;
}
