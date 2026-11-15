import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const items = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
];

function open(props: { alignItemWithTrigger?: boolean } = {}) {
    render(
        <Select items={items} defaultValue="quarterly" defaultOpen>
            <SelectTrigger aria-label="Cadence">
                <SelectValue />
            </SelectTrigger>
            <SelectContent {...props}>
                {items.map((item) => (
                    <SelectItem key={item.value} value={item.value}>
                        {item.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>,
    );

    return screen.getByRole('listbox').closest('[data-slot="select-content"]');
}

describe('SelectContent', () => {
    it('opens its list beside the trigger rather than over it', () => {
        expect(open()).toHaveAttribute('data-align-trigger', 'false');
    });

    it('still lets one select lay its list over the trigger', () => {
        expect(open({ alignItemWithTrigger: true })).toHaveAttribute(
            'data-align-trigger',
            'true',
        );
    });
});
