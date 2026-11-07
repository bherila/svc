import { useId } from 'react';
import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * One control with its label above it.
 *
 * A placeholder is not a label: it vanishes the moment a value is typed, and
 * it is the only text a reader has left to say what a half-filled form was
 * asking for. The label stays. Its `id` comes from `useId`, so the same form
 * repeated on one page (a schedule under each agreement) never shares one.
 *
 * `children` receives that id to put on the control, so the label and the
 * control cannot disagree about which one they are.
 */
export function FormField({
    label,
    hint,
    className,
    children,
}: {
    label: string;
    hint?: string;
    className?: string;
    children: (id: string) => ReactNode;
}) {
    const id = useId();

    return (
        <div className={cn('grid min-w-0 grid-cols-1 gap-1.5', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children(id)}
            {hint !== undefined && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}
