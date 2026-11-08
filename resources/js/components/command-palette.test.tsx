import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
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
        workspace_id: 'workspace-1',
        heading: 'Clients',
        recent: true,
        ...overrides,
    };
}

function action(overrides: Record<string, unknown> = {}) {
    return {
        id: 'payments',
        label: 'Go to Payments',
        href: '/workspaces/workspace-1/payments',
        keywords: ['stripe', 'square'],
        hint: null,
        ...overrides,
    };
}

type Palette = {
    audience?: 'workspace' | 'portal' | null;
    actions?: unknown[];
    recents?: unknown[];
};

let fetchMock: ReturnType<typeof vi.fn>;
let palette: Palette;

function json(body: unknown) {
    return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
}

beforeEach(() => {
    visit.mockReset();
    palette = {
        audience: 'workspace',
        actions: [
            action(),
            action({
                id: 'log_time',
                label: 'Log time',
                href: '/workspaces/workspace-1/my-work',
                keywords: ['hours', 'timer'],
            }),
        ],
        recents: [
            result({
                id: 'project-9',
                kind: 'project',
                title: 'Recent Synthetic Project',
                heading: 'Projects',
            }),
        ],
    };
    // Routed by URL, because the palette now asks two things: what it shows at
    // rest (/palette) and what matches a term (/search).
    fetchMock = vi.fn((url: string) => {
        if (url.startsWith('/palette/recents')) {
            return json({ recorded: true });
        }

        if (url.startsWith('/palette')) {
            return json({ workspace: null, ...palette });
        }

        return json({ results: [result()] });
    });
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

function searches() {
    return fetchMock.mock.calls.filter(([url]) =>
        String(url).startsWith('/search'),
    );
}

async function open(user: ReturnType<typeof userEvent.setup>) {
    await user.keyboard('{Meta>}k{/Meta}');

    return screen.findByPlaceholderText(/search clients/i);
}

describe('command palette', () => {
    it('opens on the keyboard shortcut with commands and recent items, and searches nothing', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull();

        await open(user);

        expect(await screen.findByText('Go to Payments')).toBeInTheDocument();
        expect(screen.getByText('Log time')).toBeInTheDocument();
        expect(
            screen.getByText('Recent Synthetic Project'),
        ).toBeInTheDocument();
        expect(screen.getByText(/type to search clients/i)).toBeInTheDocument();
        expect(searches()).toHaveLength(0);
        // It asks where the viewer is, and the server decides what that means.
        expect(fetchMock).toHaveBeenCalledWith(
            `/palette?path=${encodeURIComponent(window.location.pathname)}`,
            expect.anything(),
        );
    });

    /**
     * The palette asks the server rather than filtering a list it was handed,
     * because a list it was handed would be the whole workspace sitting in the
     * bundle. This is the assertion that keeps it that way: with no query
     * there is no request, and with a query the request carries the term.
     */
    it("asks the server for the typed term and groups what comes back under the server's headings", async () => {
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
        // Recent items are for the empty palette only.
        expect(screen.queryByText('Recent Synthetic Project')).toBeNull();
    });

    it("renders a kind it has never seen under the server's heading", async () => {
        fetchMock.mockImplementation((url: string) =>
            url.startsWith('/search')
                ? json({
                      results: [
                          result({
                              kind: 'file',
                              id: 'file-1',
                              title: 'scan.pdf',
                              heading: 'Files',
                          }),
                          result({
                              kind: 'brand_new_kind',
                              id: 'x-1',
                              title: 'Mystery row',
                              heading: 'Mysteries',
                          }),
                      ],
                  })
                : json({ ...palette }),
        );
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.type(await open(user), 'scan');

        expect(await screen.findByText('Files')).toBeInTheDocument();
        expect(screen.getByText('Mysteries')).toBeInTheDocument();
        expect(screen.getByText('Mystery row')).toBeInTheDocument();
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

    it('remembers a record it opened, filed under its own workspace, and only a record', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.type(await open(user), 'synth');
        await user.click(await screen.findByText('Synthetic Client'));

        const post = fetchMock.mock.calls.find(
            ([url]) => url === '/palette/recents',
        );
        expect(post).toBeDefined();
        expect(post?.[1]).toMatchObject({ method: 'POST', keepalive: true });
        expect(JSON.parse(String(post?.[1].body))).toEqual({
            workspace_id: 'workspace-1',
            kind: 'client',
            id: 'company-1',
        });
    });

    it('does not remember a row the server marked as not reopenable', async () => {
        fetchMock.mockImplementation((url: string) =>
            url.startsWith('/search')
                ? json({ results: [result({ recent: false, kind: 'time' })] })
                : json({ ...palette }),
        );
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.type(await open(user), 'synth');
        await user.click(await screen.findByText('Synthetic Client'));

        expect(visit).toHaveBeenCalled();
        expect(
            fetchMock.mock.calls.some(([url]) => url === '/palette/recents'),
        ).toBe(false);
    });

    it('opens a command by its finished URL', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await open(user);
        await user.click(await screen.findByText('Go to Payments'));

        expect(visit).toHaveBeenCalledWith('/workspaces/workspace-1/payments');
        expect(
            fetchMock.mock.calls.some(([url]) => url === '/palette/recents'),
        ).toBe(false);
    });

    it('opens a recent item', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await open(user);
        await user.click(await screen.findByText('Recent Synthetic Project'));

        expect(visit).toHaveBeenCalledWith(
            '/workspaces/workspace-1/clients/company-1',
        );
    });

    /**
     * Commands are the one list matched in the browser, and only the list the
     * server sent. What the viewer may not do is not in it to be matched.
     */
    it('matches commands by label or keyword, above the records, without asking the server about them', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        const input = await open(user);
        await screen.findByText('Go to Payments');

        await user.type(input, 'stripe');
        expect(screen.getByText('Go to Payments')).toBeInTheDocument();
        expect(screen.queryByText('Log time')).toBeNull();

        await user.clear(input);
        await user.type(input, 'hours');
        expect(screen.getByText('Log time')).toBeInTheDocument();
        expect(screen.queryByText('Go to Payments')).toBeNull();

        // Commands sit above the record groups.
        await user.clear(input);
        await user.type(input, 'time');
        const commands = await screen.findByText('Commands');
        const clients = await screen.findByText('Clients');
        expect(
            commands.compareDocumentPosition(clients) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('never invents a command the server did not send', async () => {
        palette = { audience: 'workspace', actions: [], recents: [] };
        const user = userEvent.setup();
        render(<CommandPalette />);

        const input = await open(user);
        await user.type(input, 'revenue');

        expect(screen.queryByText(/revenue/i)).toBeNull();
        expect(screen.queryByText('Go to Revenue')).toBeNull();
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
        let searchCalls = 0;

        fetchMock.mockImplementation((url: string) => {
            if (!url.startsWith('/search')) {
                return json({ ...palette });
            }

            searchCalls += 1;

            if (searchCalls === 1) {
                return new Promise((resolve) => {
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
                });
            }

            return json({
                results: [result({ title: 'Fresh Synthetic Row' })],
            });
        });

        render(<CommandPalette />);
        const input = await open(user);

        await user.type(input, 'syn');
        await waitFor(() => expect(searchCalls).toBe(1));

        await user.type(input, 'thetic');
        await waitFor(() => expect(searchCalls).toBe(2));
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

    it("speaks to a client in the portal's own words", async () => {
        palette = {
            audience: 'portal',
            actions: [
                action({
                    id: 'pay',
                    label: 'Pay an invoice',
                    href: '/portal/company-1/invoices',
                }),
            ],
            recents: [],
        };
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.keyboard('{Control>}k{/Control}');

        expect(
            await screen.findByPlaceholderText(
                /search your invoices, projects and files/i,
            ),
        ).toBeInTheDocument();
        expect(await screen.findByText('Pay an invoice')).toBeInTheDocument();
        expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull();
    });

    it('still searches when the commands could not be loaded', async () => {
        fetchMock.mockImplementation((url: string) =>
            url.startsWith('/search')
                ? json({ results: [result()] })
                : Promise.reject(new Error('offline')),
        );
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.type(await open(user), 'synth');

        expect(await screen.findByText('Synthetic Client')).toBeInTheDocument();
    });
});

describe('command palette accessibility', () => {
    it('names the dialog and the search box', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await open(user);

        const dialog = await screen.findByRole('dialog', { name: 'Search' });
        expect(
            within(dialog).getByRole('combobox', {
                name: 'Search and commands',
            }),
        ).toBeInTheDocument();
        expect(within(dialog).getByRole('listbox')).toBeInTheDocument();
    });

    it('announces how many results there are, politely', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.type(await open(user), 'synth');
        await screen.findByText('Synthetic Client');

        const status = await screen.findByRole('status');
        expect(status).toHaveAttribute('aria-live', 'polite');
        await waitFor(() => expect(status).toHaveTextContent('1 result.'));
    });

    it('says what is available before anything is typed, and when nothing matched', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        const input = await open(user);
        const status = await screen.findByRole('status');
        await waitFor(() =>
            expect(status).toHaveTextContent(
                '3 commands and 1 recent item available. Type to search.',
            ),
        );

        fetchMock.mockImplementation((url: string) =>
            url.startsWith('/search') ? json({ results: [] }) : json({}),
        );
        await user.type(input, 'zzzz');
        await waitFor(() => expect(status).toHaveTextContent('No results.'));
        expect(screen.getByText('Nothing matched.')).toBeInTheDocument();
    });

    it('moves through the rows and opens one with the keyboard alone', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await open(user);
        await screen.findByText('Go to Payments');

        await user.keyboard('{ArrowDown}{Enter}');

        expect(visit).toHaveBeenCalledTimes(1);
        expect(String(visit.mock.calls[0][0])).toMatch(/^\/workspaces\//);
    });

    it('keeps hostile names inside the window', async () => {
        const long = 'Unbroken'.repeat(30);
        fetchMock.mockImplementation((url: string) =>
            url.startsWith('/search')
                ? json({
                      results: [
                          result({
                              title: long,
                              subtitle: long,
                              workspace: long,
                          }),
                          result({
                              id: 'other',
                              title: 'Other',
                              workspace: 'Another Workspace',
                          }),
                      ],
                  })
                : json({
                      ...palette,
                      actions: [action({ label: long, hint: long })],
                  }),
        );
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.type(await open(user), 'unbroken');
        await screen.findAllByText(long);

        expect(horizontalOverflowRisks(document.body)).toEqual([]);
    });
});

describe('keyboard shortcuts', () => {
    it('opens the palette with a slash when nothing is being typed', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.keyboard('/');

        expect(
            await screen.findByPlaceholderText(/search clients/i),
        ).toBeInTheDocument();
    });

    it('lists the shortcuts on a question mark, and closes them on escape', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.keyboard('?');

        const dialog = await screen.findByRole('dialog', {
            name: 'Keyboard shortcuts',
        });
        expect(within(dialog).getByText('Show these shortcuts')).toBeVisible();
        expect(
            within(dialog).getByText('Open the search palette'),
        ).toBeVisible();
        expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull();

        await user.keyboard('{Escape}');
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Keyboard shortcuts' }),
            ).toBeNull(),
        );
    });

    it("opens the shortcut sheet from the palette's own control", async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await open(user);
        await user.click(
            await screen.findByRole('button', { name: 'Shortcuts' }),
        );

        expect(
            await screen.findByRole('dialog', { name: 'Keyboard shortcuts' }),
        ).toBeInTheDocument();
        expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull();
    });

    /**
     * The point of a single-key shortcut is that it is only a shortcut when
     * nobody is typing. Every field a person can type into is checked, because
     * the failure is silent: the character they meant to type is swallowed.
     */
    it.each([
        ['an input', () => document.createElement('input')],
        ['a textarea', () => document.createElement('textarea')],
        [
            'a contenteditable',
            () => {
                const element = document.createElement('div');
                element.setAttribute('contenteditable', 'true');
                // jsdom does not compute isContentEditable from the attribute.
                Object.defineProperty(element, 'isContentEditable', {
                    value: true,
                });
                element.tabIndex = 0;

                return element;
            },
        ],
        [
            'an element with a text role',
            () => {
                const element = document.createElement('div');
                element.setAttribute('role', 'textbox');
                element.tabIndex = 0;

                return element;
            },
        ],
        ['a select', () => document.createElement('select')],
    ])('does nothing for / or ? while typing in %s', async (_name, make) => {
        const user = userEvent.setup();
        render(<CommandPalette />);
        const field = make();
        document.body.append(field);
        field.focus();

        await user.keyboard('/?');

        expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull();
        expect(
            screen.queryByRole('dialog', { name: 'Keyboard shortcuts' }),
        ).toBeNull();

        field.remove();
    });

    it('lets a question mark be typed in a field and types into the field', async () => {
        const user = userEvent.setup();
        render(
            <>
                <label>
                    Note
                    <textarea />
                </label>
                <CommandPalette />
            </>,
        );

        await user.type(screen.getByLabelText('Note'), 'why? a/b');

        expect(screen.getByLabelText('Note')).toHaveValue('why? a/b');
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it("does not take the slash typed into the palette's own search box", async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        const input = await open(user);
        await user.type(input, 'a/b?');

        expect(input).toHaveValue('a/b?');
        expect(
            screen.queryByRole('dialog', { name: 'Keyboard shortcuts' }),
        ).toBeNull();
    });

    it('stands down for a held modifier, and while another dialog is open', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await user.keyboard('{Control>}/{/Control}');
        await user.keyboard('{Alt>}?{/Alt}');
        expect(screen.queryByRole('dialog')).toBeNull();

        const other = document.createElement('div');
        other.setAttribute('role', 'dialog');
        document.body.append(other);

        await user.keyboard('/');
        expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull();

        other.remove();
    });

    it('toggles on the chord and closes on escape', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);

        await open(user);
        await user.keyboard('{Meta>}k{/Meta}');
        await waitFor(() =>
            expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull(),
        );

        await open(user);
        await user.keyboard('{Escape}');
        await waitFor(() =>
            expect(screen.queryByPlaceholderText(/search clients/i)).toBeNull(),
        );
    });
});
