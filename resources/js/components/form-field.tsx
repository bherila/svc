import { useEffect, useId, useRef } from 'react';
import type { ReactNode, RefObject } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** The attributes that tie a control to the message explaining it is wrong. */
export function fieldErrorProps(id: string, error: string | undefined) {
    return error === undefined || error === ''
        ? {}
        : { 'aria-invalid': true as const, 'aria-describedby': `${id}-error` };
}

/**
 * Move focus to the first invalid control inside `container` whenever the set
 * of errors changes to a non-empty one. For forms laid out by hand rather than
 * with `FormField`: give each failing control `fieldErrorProps` and pass the
 * errors object's keys (or the message) as `errorKey`.
 */
export function useFocusFirstInvalid(
    container: RefObject<HTMLElement | null>,
    errorKey: string,
) {
    useEffect(() => {
        if (errorKey === '') {
            return;
        }

        container.current
            ?.querySelector<HTMLElement>('[aria-invalid="true"]')
            ?.focus();
    }, [container, errorKey]);
}

/**
 * The message under a field. `role="alert"` announces it the moment it
 * appears; its `id` is what `fieldErrorProps` points the control's
 * `aria-describedby` at, so the message is read again when the field is focused.
 */
export function FieldError({
    id,
    children,
}: {
    id: string;
    children: ReactNode;
}) {
    return (
        <p
            id={`${id}-error`}
            role="alert"
            className="text-sm wrap-anywhere text-destructive"
        >
            {children}
        </p>
    );
}

/**
 * One control with its label above it.
 *
 * A placeholder is not a label: it vanishes the moment a value is typed, and
 * it is the only text a reader has left to say what a half-filled form was
 * asking for. The label stays. Its `id` comes from `useId`, so the same form
 * repeated on one page (a schedule under each agreement) never shares one.
 *
 * `children` receives that id to put on the control, so the label and the
 * control cannot disagree about which one they are, and the attributes that
 * tie it to its `error` (`aria-invalid`, `aria-describedby`) to spread on it.
 * When an error arrives and this is the first invalid field in its form, focus
 * moves to the control, so a submit that fails lands the reader on the first
 * thing to fix instead of leaving them at the button.
 */
export function FormField({
    label,
    hint,
    error,
    className,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    className?: string;
    children: (
        id: string,
        aria: ReturnType<typeof fieldErrorProps>,
    ) => ReactNode;
}) {
    const id = useId();
    const wrapper = useRef<HTMLDivElement>(null);
    const invalid = error !== undefined && error !== '';

    useEffect(() => {
        if (!invalid || wrapper.current === null) {
            return;
        }

        const scope = wrapper.current.closest('form') ?? document;
        const first = scope.querySelector<HTMLElement>('[aria-invalid="true"]');

        if (first !== null && wrapper.current.contains(first)) {
            first.focus();
        }
    }, [invalid, error]);

    return (
        <div
            ref={wrapper}
            className={cn('grid min-w-0 grid-cols-1 gap-1.5', className)}
        >
            <Label htmlFor={id}>{label}</Label>
            {children(id, fieldErrorProps(id, error))}
            {hint !== undefined && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            {invalid && <FieldError id={id}>{error}</FieldError>}
        </div>
    );
}
