import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CommandPalette, CommandPaletteTrigger } from './command-palette';

const visit = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { visit: (...args: unknown[]) => visit(...args) },
}));

function result(overrides: Record<string, unknown> = {}) {
    return {
        kind: 'client',
        id: 'company-1',
        title: 'Synthetic Client',
        subtitle: null,
        href: '/workspaces/workspace-1/clients/company-1',
        workspace: 'Synthetic Workspace',
        ...overrides,
    };
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
    fetchMock = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ results: [result()] }),
        }),
    );
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

async function open(user: ReturnType<typeof userEvent.setup>) {
    await user.keyboard('{Meta>}k{/Meta}');

    return screen.findByPlaceholderText(/search clients/i);
}

describe('command palette', () => {
    it('opens on the keyboard shortcut and starts with nothing searched', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull();

        await open(user);

        expect(screen.getByText(/type to search clients/i)).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    /**
     * The palette asks the server rather than filtering a list it was handed,
     * because a list it was handed would be the whole workspace sitting in the
     * bundle. This is the assertion that keeps it that way: with no query
     * there is no request, and with a query the request carries the term.
     */
    it('asks the server for the typed term and groups what comes back', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        const input = await open(user);
        await user.type(input, 'synth');

        await waitFor(() =>
            expect(fetchMock).toHaveBeenCalledWith(
                '/search?q=synth',
                expect.anything(),
            ),
        );

        expect(await screen.findByText('Clients')).toBeInTheDocument();
        expect(screen.getByText('Synthetic Client')).toBeInTheDocument();
    });

    it('navigates to the row the server chose the destination for', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        const input = await open(user);
        await user.type(input, 'synth');

        const row = await screen.findByText('Synthetic Client');
        await user.click(row);

        expect(visit).toHaveBeenCalledWith(
            '/workspaces/workspace-1/clients/company-1',
        );
        await waitFor(() =>
            expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull(),
        );
    });

    /**
     * The classic search-as-you-type bug: an early request resolving after a
     * later one leaves results for a term nobody is looking at any more. The
     * palette keeps a request counter for exactly this, so the slow "syn"
     * response below must not replace the fast "synthetic" one.
     */
    it('ignores a stale response that arrives after a newer one', async () => {
        const user = userEvent.setup();
        // Held in an object rather than a bare `let`: assigning inside the
        // promise executor is invisible to control-flow analysis, which then
        // narrows a `let` to its initial value.
        const first: { release?: () => void } = {};

        fetchMock.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    first.release = () =>
                        resolve({
                            ok: true,
                            json: () =>
                                Promise.resolve({
                                    results: [
                                        result({
                                            title: 'Stale Synthetic Row',
                                        }),
                                    ],
                                }),
                        });
                }),
        );
        fetchMock.mockImplementationOnce(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        results: [result({ title: 'Fresh Synthetic Row' })],
                    }),
            }),
        );

        render(<CommandPalette />);
        const input = await open(user);

        await user.type(input, 'syn');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        await user.type(input, 'thetic');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(
            await screen.findByText('Fresh Synthetic Row'),
        ).toBeInTheDocument();

        // Only now does the first request come back.
        first.release?.();

        await waitFor(() =>
            expect(screen.getByText('Fresh Synthetic Row')).toBeInTheDocument(),
        );
        expect(screen.queryByText('Stale Synthetic Row')).toBeNull();
    });

    /**
     * Reopening must not show the answer to the previous question. Asserted
     * because the results live in state that outlives the dialog.
     */
    it('forgets the previous search when it closes', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        const input = await open(user);
        await user.type(input, 'synth');
        expect(await screen.findByText('Synthetic Client')).toBeInTheDocument();

        await user.keyboard('{Escape}');
        await waitFor(() =>
            expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull(),
        );

        await open(user);

        expect(screen.queryByText('Synthetic Client')).toBeNull();
        expect(screen.getByText(/type to search clients/i)).toBeInTheDocument();
    });

    /**
     * A shortcut nobody is told about is a feature only its author has, and
     * the trigger lives in a different React tree from the palette - so this
     * proves the one channel between them actually carries.
     */
    it('opens from a trigger mounted outside its own tree', async () => {
        const user = userEvent.setup();
        render(
            <>
                <CommandPaletteTrigger />
                <CommandPalette />
            </>,
        );

        await user.click(screen.getByRole('button', { name: /search/i }));

        expect(
            await screen.findByPlaceholderText(/search clients/i),
        ).toBeInTheDocument();
    });
});
