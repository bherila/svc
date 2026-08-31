import { MonitorIcon, MoonIcon, SunIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

type Appearance = 'light' | 'dark' | 'system';

const storageKey = 'svc:appearance';

function isAppearance(value: string | null): value is Appearance {
    return value === 'light' || value === 'dark' || value === 'system';
}

function readAppearance(): Appearance {
    if (typeof window === 'undefined') {
        return 'system';
    }

    const stored = window.localStorage.getItem(storageKey);

    return isAppearance(stored) ? stored : 'system';
}

function applyAppearance(appearance: Appearance): void {
    const isDark =
        appearance === 'dark' ||
        (appearance === 'system' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
}

export function AppearanceSelector() {
    const [appearance, setAppearance] = useState<Appearance>(readAppearance);

    useEffect(() => {
        applyAppearance(appearance);
        window.localStorage.setItem(storageKey, appearance);

        if (appearance !== 'system') {
            return;
        }

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const updateAppearance = () => applyAppearance('system');

        media.addEventListener('change', updateAppearance);

        return () => media.removeEventListener('change', updateAppearance);
    }, [appearance]);

    return (
        <label className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
            {appearance === 'light' ? (
                <SunIcon aria-hidden="true" className="size-4" />
            ) : appearance === 'dark' ? (
                <MoonIcon aria-hidden="true" className="size-4" />
            ) : (
                <MonitorIcon aria-hidden="true" className="size-4" />
            )}
            <span className="sr-only">Appearance</span>
            <select
                aria-label="Appearance"
                className="rounded-lg border border-border bg-background px-2 py-1 text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                value={appearance}
                onChange={(event) =>
                    setAppearance(event.target.value as Appearance)
                }
            >
                <option value="system">System</option>
                <option value="light">Light</option>
                <option value="dark">Dark</option>
            </select>
        </label>
    );
}
