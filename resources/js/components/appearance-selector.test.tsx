import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { hydrateRoot } from 'react-dom/client';
import { renderToString } from 'react-dom/server';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { AppearanceSelector } from '@/components/appearance-selector';
import { resetAppearanceStoreForTests } from '@/lib/appearance';

beforeEach(() => {
    resetAppearanceStoreForTests();
});

afterEach(() => {
    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = '';
    window.localStorage.clear();
    resetAppearanceStoreForTests();
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

test('hydrates from the server snapshot before reconciling persisted dark mode', async () => {
    window.localStorage.setItem('svc:appearance', 'dark');

    const serverMarkup = renderToString(<AppearanceSelector />);
    const container = document.createElement('div');
    const recoverableError = vi.fn();
    container.innerHTML = serverMarkup;
    document.body.append(container);

    expect(
        within(container).getByRole('combobox', { name: 'Appearance' }),
    ).toHaveValue('system');

    const root = hydrateRoot(container, <AppearanceSelector />, {
        onRecoverableError: recoverableError,
    });

    await act(async () => undefined);

    expect(recoverableError).not.toHaveBeenCalled();
    expect(
        within(container).getByRole('combobox', { name: 'Appearance' }),
    ).toHaveValue('dark');
    expect(document.documentElement).toHaveClass('dark');

    await act(async () => root.unmount());
    container.remove();
});

test('keeps working in memory when browser storage is blocked', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new DOMException('Storage is blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('Storage is blocked');
    });
    const user = userEvent.setup();

    render(<AppearanceSelector />);
    await user.selectOptions(screen.getByLabelText('Appearance'), 'dark');

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

test('shares one preference and one system listener across consumers', async () => {
    const media = {
        matches: false,
        media: '(prefers-color-scheme: dark)',
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
    };
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => media as unknown as MediaQueryList),
    );
    const user = userEvent.setup();

    render(
        <>
            <AppearanceSelector />
            <AppearanceSelector />
        </>,
    );

    expect(media.addEventListener).toHaveBeenCalledTimes(1);
    await user.selectOptions(screen.getAllByLabelText('Appearance')[0], 'dark');
    expect(screen.getAllByLabelText('Appearance')).toHaveLength(2);
    expect(screen.getAllByLabelText('Appearance')[0]).toHaveValue('dark');
    expect(screen.getAllByLabelText('Appearance')[1]).toHaveValue('dark');
    expect(media.removeEventListener).toHaveBeenCalledTimes(1);
});

test('reconciles appearance changes made in another tab', () => {
    render(<AppearanceSelector />);

    act(() => {
        window.dispatchEvent(
            new StorageEvent('storage', {
                key: 'svc:appearance',
                newValue: 'dark',
            }),
        );
    });

    expect(screen.getByLabelText('Appearance')).toHaveValue('dark');
    expect(document.documentElement).toHaveClass('dark');
    expect(document.documentElement.style.colorScheme).toBe('dark');
});
