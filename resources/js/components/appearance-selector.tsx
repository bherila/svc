import { MonitorIcon, MoonIcon, SunIcon } from 'lucide-react';
import { OptionSelect } from '@/components/ui/option-select';
import { isAppearance, useAppearance } from '@/lib/appearance';

const APPEARANCES = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
];

export function AppearanceSelector() {
    const [appearance, setAppearance] = useAppearance();

    return (
        <label className="hidden items-center gap-1.5 text-sm text-muted-foreground sm:inline-flex">
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
            <OptionSelect
                aria-label="Appearance"
                // Capped on a phone, where the bar has three things on it that
                // matter more: which client, which section, and the way out.
                // The icon beside it already says which mode is active, so a
                // clipped label costs nothing the reader needs.
                className="w-auto max-w-24 sm:max-w-none"
                value={appearance}
                options={APPEARANCES}
                onValueChange={(next) => {
                    if (isAppearance(next)) {
                        setAppearance(next);
                    }
                }}
            />
        </label>
    );
}
