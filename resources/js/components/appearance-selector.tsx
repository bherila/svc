import { MonitorIcon, MoonIcon, SunIcon } from 'lucide-react';
import { isAppearance, useAppearance } from '@/lib/appearance';

export function AppearanceSelector() {
    const [appearance, setAppearance] = useAppearance();

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
