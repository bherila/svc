import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, test } from 'vitest';
import { FormField } from '@/components/form-field';
import { SkipLink } from '@/components/skip-link';
import { Input } from '@/components/ui/input';

test('the skip link is the first tab stop and moves focus into main', async () => {
    const user = userEvent.setup();

    render(
        <>
            <SkipLink />
            <header>
                <button type="button">Navigation</button>
            </header>
            <main>
                <h1>Page</h1>
            </main>
        </>,
    );
    await user.tab();

    const link = screen.getByRole('link', { name: 'Skip to content' });
    expect(link).toHaveFocus();

    await user.keyboard('{Enter}');

    expect(screen.getByRole('main')).toHaveFocus();
    expect(screen.getByRole('main')).toHaveAttribute('tabindex', '-1');
});

test('a field error is announced, tied to its control, and focused', () => {
    const { rerender } = render(
        <form>
            <FormField label="Email">
                {(id, aria) => <Input id={id} {...aria} />}
            </FormField>
        </form>,
    );

    expect(screen.queryByRole('alert')).toBeNull();

    rerender(
        <form>
            <FormField label="Email" error="Enter a valid email.">
                {(id, aria) => <Input id={id} {...aria} />}
            </FormField>
        </form>,
    );

    const input = screen.getByLabelText('Email');
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAccessibleDescription('Enter a valid email.');
    expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid email.');
    expect(input).toHaveFocus();
});
