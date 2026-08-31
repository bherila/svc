import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, test, vi } from 'vitest';
import { AppearanceSelector } from '@/components/appearance-selector';

afterEach(() => {
    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = '';
    window.localStorage.clear();
    vi.unstubAllGlobals();
});

test('persists the selected dark appearance and updates the document root', async () => {
    const user = userEvent.setup();

    render(<AppearanceSelector />);

    await user.selectOptions(screen.getByLabelText('Appearance'), 'dark');

    expect(document.documentElement).toHaveClass('dark');
    expect(document.documentElement.style.colorScheme).toBe('dark');
    expect(window.localStorage.getItem('svc:appearance')).toBe('dark');
});

test('restores a persisted appearance when an authenticated page mounts', () => {
    window.localStorage.setItem('svc:appearance', 'dark');

    render(<AppearanceSelector />);

    expect(screen.getByLabelText('Appearance')).toHaveValue('dark');
    expect(document.documentElement).toHaveClass('dark');
    expect(document.documentElement.style.colorScheme).toBe('dark');
});

test('follows system appearance changes and removes its listener', () => {
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
    const { unmount } = render(<AppearanceSelector />);

    media.matches = true;
    onChange?.({ matches: true } as MediaQueryListEvent);

    expect(document.documentElement).toHaveClass('dark');
    expect(document.documentElement.style.colorScheme).toBe('dark');

    media.matches = false;
    onChange?.({ matches: false } as MediaQueryListEvent);

    expect(document.documentElement).not.toHaveClass('dark');
    expect(document.documentElement.style.colorScheme).toBe('light');

    unmount();
    expect(media.removeEventListener).toHaveBeenCalledWith(
        'change',
        expect.any(Function),
    );
});
