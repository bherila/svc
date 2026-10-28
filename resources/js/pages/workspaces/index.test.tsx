import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import WorkspaceSelector from '@/pages/workspaces/index';
import { sharedPageProps } from '@/test/shared-page-props';

const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: inertia,
    usePage: () => ({ props: sharedPageProps() }),
}));

describe('workspace selector', () => {
    it('offers one row per workspace and nothing beneath it', () => {
        render(
            <WorkspaceSelector
                create_url="/synthetic/create-workspace"
                workspaces={[
                    {
                        id: 'workspace-1',
                        name: 'Aa Synthetic Workspace',
                        enter_href: '/workspaces/workspace-1',
                    },
                    {
                        id: 'workspace-2',
                        name: 'Bb Synthetic Workspace',
                        enter_href: '/workspaces/workspace-2',
                    },
                ]}
            />,
        );

        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(
            screen.getByRole('link', { name: /Aa Synthetic Workspace/ }),
        ).toHaveAttribute('href', '/workspaces/workspace-1');
    });

    /**
     * The wordmark returns here from everywhere else, so on this screen it has
     * nowhere to go. A link that reloads the page you are on reads as broken.
     */
    it('does not link the wordmark to itself', () => {
        render(
            <WorkspaceSelector
                create_url="/synthetic/create-workspace"
                workspaces={[]}
            />,
        );

        expect(screen.getByText('SVC').tagName).toBe('SPAN');
    });

    it('says what to do when there is no workspace yet', () => {
        render(
            <WorkspaceSelector
                create_url="/synthetic/create-workspace"
                workspaces={[]}
            />,
        );

        expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /New workspace/ }),
        ).toBeInTheDocument();
    });

    /**
     * The theme control and the account menu are here because they mean the
     * same thing on every screen; the switcher and the tabs are not, because
     * there is no workspace yet for them to be about.
     */
    it('carries the global controls and no client chrome', () => {
        render(
            <WorkspaceSelector
                create_url="/synthetic/create-workspace"
                workspaces={[]}
            />,
        );

        expect(
            screen.getByRole('combobox', { name: 'Appearance' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Account and settings' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Current client' }),
        ).not.toBeInTheDocument();
    });
});

let fetchMock: ReturnType<typeof vi.fn>;
beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    inertia.visit.mockClear();
});
afterEach(() => vi.unstubAllGlobals());

function respond(status: number, body: unknown) {
    fetchMock.mockResolvedValueOnce(
        new Response(JSON.stringify(body), {
            status,
            headers: { 'Content-Type': 'application/json' },
        }),
    );
}
function request(index = 0) {
    const [url, init] = fetchMock.mock.calls[index] as [string, RequestInit];

    return {
        url,
        method: init.method,
        body: JSON.parse(String(init.body)),
        key: (init.headers as Record<string, string>)['Idempotency-Key'],
    };
}
function openCreate() {
    render(
        <WorkspaceSelector
            create_url="/synthetic/create-workspace"
            workspaces={[]}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'New workspace' }));
    fireEvent.change(screen.getByLabelText('Name', { exact: true }), {
        target: { value: 'Synthetic API workspace' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Create' }));
}

describe('workspace creation through the API', () => {
    it('uses the finished operation URL and follows the result URL', async () => {
        respond(201, {
            data: {
                id: 'workspace-1',
                name: 'Synthetic API workspace',
                web_url: '/synthetic/created-workspace-destination',
            },
        });
        openCreate();
        await waitFor(() =>
            expect(inertia.visit).toHaveBeenCalledWith(
                '/synthetic/created-workspace-destination',
            ),
        );
        expect(request()).toMatchObject({
            url: '/synthetic/create-workspace',
            method: 'POST',
            body: { name: 'Synthetic API workspace' },
        });
        expect(request().key).toBeTruthy();
    });

    it('reuses the receipt after a lost response rather than creating another workspace', async () => {
        fetchMock.mockRejectedValueOnce(
            new TypeError('Synthetic network loss'),
        );
        respond(201, { data: { web_url: '/synthetic/replayed-workspace' } });
        openCreate();
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be reached',
        );
        expect(screen.getByLabelText('Name', { exact: true })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Check creation' }));
        await waitFor(() =>
            expect(inertia.visit).toHaveBeenCalledWith(
                '/synthetic/replayed-workspace',
            ),
        );
        expect(request(0)).toEqual(request(1));
    });

    it('keeps the entered name visible after a validation refusal', async () => {
        respond(422, {
            message: 'Validation failed.',
            errors: { name: ['Synthetic name refused.'] },
        });
        openCreate();
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Synthetic name refused.',
        );
        expect(screen.getByLabelText('Name', { exact: true })).toHaveValue(
            'Synthetic API workspace',
        );
        expect(inertia.visit).not.toHaveBeenCalled();
    });
    it('unlocks correction after an uncertain request is definitively refused as invalid', async () => {
        fetchMock.mockRejectedValueOnce(
            new TypeError('Synthetic network loss'),
        );
        respond(422, {
            message: 'Validation failed.',
            errors: { name: ['Correct the name.'] },
        });
        respond(201, { data: { web_url: '/synthetic/corrected-workspace' } });
        openCreate();
        await screen.findByRole('alert');
        fireEvent.click(screen.getByRole('button', { name: 'Check creation' }));
        await waitFor(() =>
            expect(
                screen.getByLabelText('Name', { exact: true }),
            ).toBeEnabled(),
        );
        fireEvent.change(screen.getByLabelText('Name', { exact: true }), {
            target: { value: 'Corrected workspace' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Create' }));
        await waitFor(() =>
            expect(inertia.visit).toHaveBeenCalledWith(
                '/synthetic/corrected-workspace',
            ),
        );
        expect(request(0)).toEqual(request(1));
        expect(request(2).key).not.toBe(request(0).key);
        expect(request(2).body).toEqual({ name: 'Corrected workspace' });
    });

    it('replays safely after the successful response body is interrupted', async () => {
        fetchMock.mockResolvedValueOnce(
            new Response('{"data":', { status: 201 }),
        );
        respond(201, {
            data: { web_url: '/synthetic/body-recovered-workspace' },
        });
        openCreate();
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be read',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check creation' }));
        await waitFor(() =>
            expect(inertia.visit).toHaveBeenCalledWith(
                '/synthetic/body-recovered-workspace',
            ),
        );
        expect(request(0)).toEqual(request(1));
    });

    it('keeps an uncertain receipt across closing and reopening the dialog', async () => {
        fetchMock.mockRejectedValueOnce(
            new TypeError('Synthetic network loss'),
        );
        respond(201, { data: { web_url: '/synthetic/reopened-workspace' } });
        openCreate();
        await screen.findByRole('alert');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'New workspace' }));
        expect(screen.getByLabelText('Name', { exact: true })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Check creation' }));
        await waitFor(() =>
            expect(inertia.visit).toHaveBeenCalledWith(
                '/synthetic/reopened-workspace',
            ),
        );
        expect(request(0)).toEqual(request(1));
    });
});
