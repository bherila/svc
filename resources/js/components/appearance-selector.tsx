import { MonitorIcon, MoonIcon, SunIcon } from 'lucide-react';
import { isAppearance, useAppearance } from '@/lib/appearance';

export function AppearanceSelector() {
    const [appearance, setAppearance] = useAppearance();

    return (
        <label className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
            {/*
             * Redundant with the control beside it, and the first thing to go
             * when the bar is short of room: on a phone the client's name and
             * the section menu are what the reader is there for.
             */}
            {appearance === 'light' ? (
                <SunIcon
                    aria-hidden="true"
                    className="hidden size-4 sm:block"
                />
            ) : appearance === 'dark' ? (
                <MoonIcon
                    aria-hidden="true"
                    className="hidden size-4 sm:block"
                />
            ) : (
                <MonitorIcon
                    aria-hidden="true"
                    className="hidden size-4 sm:block"
                />
            )}
            <span className="sr-only">Appearance</span>
            <select
                aria-label="Appearance"
                // Capped on a phone, where the bar has three things on it that
                // matter more: which client, which section, and the way out.
                // The icon beside it already says which mode is active, so a
                // clipped label costs nothing the reader needs.
                className="max-w-24 rounded-lg border border-border bg-background px-2 py-1 text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring sm:max-w-none"
                value={appearance}
                onChange={(event) => {
                    if (isAppearance(event.target.value)) {
                        setAppearance(event.target.value);
                    }
                }}
            >
                <option value="system">System</option>
                <option value="light">Light</option>
                <option value="dark">Dark</option>
            </select>
        </label>
    );
}
