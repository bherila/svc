import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

export type SelectOption = {
    value: string;
    label: string;
    disabled?: boolean;
};

/**
 * Base UI reads an empty string as "nothing chosen" and prints the placeholder,
 * so an option whose stored value is `''` ("All types", "Whole client") is
 * carried through the primitive under this stand-in and mapped back at the edge.
 */
const EMPTY = '__empty__';
const outward = (value: string) => (value === '' ? EMPTY : value);

/**
 * A list of options chosen from, drawn with the shared `Select`.
 *
 * One place that remembers the three things a bare `Select` makes easy to get
 * wrong: the root is given `items`, so the trigger prints the label and not the
 * stored value; the trigger is named, either by a `<label htmlFor>` pointing at
 * `id` or by `aria-label`; and the value handed to the caller is always a
 * string. A native `<select>` is not an alternative - `native-select.test.ts`
 * fails the build on one.
 */
export function OptionSelect({
    id,
    value,
    options,
    onValueChange,
    'aria-label': ariaLabel,
    'aria-describedby': describedBy,
    'aria-invalid': invalid,
    disabled,
    className,
    placeholder,
}: {
    id?: string;
    value: string;
    options: readonly SelectOption[];
    onValueChange: (value: string) => void;
    'aria-label'?: string;
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
    disabled?: boolean;
    className?: string;
    placeholder?: string;
}) {
    return (
        <Select
            items={options.map((option) => ({
                value: outward(option.value),
                label: option.label,
            }))}
            value={outward(value)}
            disabled={disabled}
            onValueChange={(next) => {
                if (typeof next === 'string') {
                    onValueChange(next === EMPTY ? '' : next);
                }
            }}
        >
            <SelectTrigger
                id={id}
                aria-label={ariaLabel}
                aria-describedby={describedBy}
                aria-invalid={invalid}
                className={cn('w-full pointer-coarse:h-11', className)}
            >
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem
                        key={option.value}
                        value={outward(option.value)}
                        disabled={option.disabled}
                    >
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
