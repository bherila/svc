import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StatTile } from '@/components/ui/stat-tile';

describe('StatTile', () => {
    it('shows a label, a value and one line of context', () => {
        render(<StatTile label="Open A/R" value="$1,200" context="2 overdue" />);

        expect(screen.getByText('Open A/R')).toBeInTheDocument();
        expect(screen.getByText('$1,200')).toBeInTheDocument();
        expect(screen.getByText('2 overdue')).toBeInTheDocument();
    });

    it('uses the tile padding and radius tokens, and wraps long values', () => {
        const { container } = render(
            <StatTile label="x" value={'9'.repeat(80)} />,
        );
        const tile = container.querySelector('[data-slot="stat-tile"]');

        expect(tile).toHaveClass('rounded-[12px]', 'px-[18px]', 'py-4');
        expect(screen.getByText('9'.repeat(80))).toHaveClass('wrap-anywhere');
    });
});
