import { MinusIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatHours, parseDuration } from '@/lib/time';

const STEPS = [5, 15];

/**
 * Duration entry.
 *
 * Logging time is the one thing on this screen done dozens of times a day, so
 * it accepts what an operator would actually type - `1:30`, `1.5`, `90m` - and
 * carries the predecessor's nudge buttons, which exist because most
 * corrections are one short step rather than a retype.
 */
export function DurationField({
    minutes,
    onChange,
    id = 'minutes',
}: {
    minutes: number;
    onChange: (minutes: number) => void;
    id?: string;
}) {
    // The field owns the text and the parent owns the number. Keeping the text
    // local is what lets a half-typed `1:` stand; every path that changes the
    // number writes the text alongside it, so the two never need reconciling
    // in an effect.
    const [text, setText] = useState(() => formatHours(minutes));

    const apply = (value: number) => {
        const bounded = Math.max(1, value);
        onChange(bounded);
        setText(formatHours(bounded));
    };

    const commit = (value: string) => {
        const parsed = parseDuration(value);

        if (parsed !== null && parsed > 0) {
            apply(parsed);

            return;
        }

        setText(formatHours(minutes));
    };

    const nudge = (delta: number) => {
        apply((parseDuration(text) ?? minutes) + delta);
    };

    return (
        <div className="grid grid-cols-1 gap-1.5">
            <Label htmlFor={id}>Duration</Label>
            <div className="flex items-center gap-1.5">
                <Input
                    id={id}
                    value={text}
                    inputMode="text"
                    className="w-24 tabular-nums"
                    onChange={(event) => setText(event.target.value)}
                    onBlur={(event) => commit(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            commit(text);
                        }
                    }}
                />
                <div className="flex items-center gap-1">
                    {STEPS.map((step) => (
                        <Button
                            key={`minus-${step}`}
                            type="button"
                            variant="outline"
                            size="xs"
                            onClick={() => nudge(-step)}
                            aria-label={`Subtract ${step} minutes`}
                        >
                            <MinusIcon />
                            {step}
                        </Button>
                    ))}
                    {STEPS.map((step) => (
                        <Button
                            key={`plus-${step}`}
                            type="button"
                            variant="outline"
                            size="xs"
                            onClick={() => nudge(step)}
                            aria-label={`Add ${step} minutes`}
                        >
                            <PlusIcon />
                            {step}
                        </Button>
                    ))}
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                {minutes} minutes · type <code>1:30</code>, <code>1.5</code> or{' '}
                <code>90m</code>
            </p>
        </div>
    );
}
