import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, test, vi } from 'vitest';
import { AppearanceSelector } from '@/components/appearance-selector';

afterEach(() => {
    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = '';
    window.localStorage.clear();
});

test('persists the selected dark appearance and updates the document root', async () => {
    const user = userEvent.setup();

    render(<AppearanceSelector />);

    await user.selectOptions(screen.getByLabelText('Appearance'), 'dark');

    expect(document.documentElement).toHaveClass('dark');
    expect(document.documentElement.style.colorScheme).toBe('dark');
    expect(window.localStorage.getItem('svc:appearance')).toBe('dark');
});

test('follows a system appearance change', () => {
    let onChange: ((event: MediaQueryListEvent) => void) | undefined;
    const media = {
        matches: false,
        media: '(prefers-color-scheme: dark)',
        onchange: null,
        addEventListener: vi.fn((_type, listener) => {
            onChange = listener as (event: MediaQueryListEvent) => void;
        }),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
    };

    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => media as unknown as MediaQueryList),
    );
    render(<AppearanceSelector />);

    media.matches = true;
    onChange?.({ matches: true } as MediaQueryListEvent);

    expect(document.documentElement).toHaveClass('dark');
    expect(document.documentElement.style.colorScheme).toBe('dark');
});
