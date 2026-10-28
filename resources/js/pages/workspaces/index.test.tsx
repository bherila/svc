import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import WorkspaceSelector from '@/pages/workspaces/index';
import { sharedPageProps } from '@/test/shared-page-props';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(
        (
            _url: string,
            options?: { onSuccess?: () => void; onFinish?: () => void },
        ) => {
            options?.onSuccess?.();
            options?.onFinish?.();
        },
    ),
}));

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
    inertia.visit.mockReset().mockImplementation((_url, options) => {
        options?.onSuccess?.();
        options?.onFinish?.();
    });
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
                expect.any(Object),
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
                expect.any(Object),
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
    it.each([401, 403, 404, 409, 422])(
        'keeps an unknown workspace creation latched after later status %s',
        async (status) => {
            fetchMock.mockRejectedValueOnce(
                new TypeError('Synthetic network loss'),
            );
            respond(status, { message: 'Synthetic later refusal.' });
            respond(201, {
                data: { web_url: '/synthetic/recovered-workspace' },
            });
            openCreate();
            await screen.findByRole('alert');
            fireEvent.click(
                screen.getByRole('button', { name: 'Check creation' }),
            );
            await screen.findByText(
                status === 401
                    ? /Your session has expired/
                    : 'Synthetic later refusal.',
            );
            expect(
                screen.getByLabelText('Name', { exact: true }),
            ).toBeDisabled();
            expect(
                screen.getByRole('button', { name: 'Cancel' }),
            ).toBeDisabled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Check creation' }),
            );
            await waitFor(() => expect(inertia.visit).toHaveBeenCalled());
            expect(request(0)).toEqual(request(1));
            expect(request(0)).toEqual(request(2));
        },
    );

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
                expect.any(Object),
            ),
        );
        expect(request(0)).toEqual(request(1));
    });

    it('keeps cancellation from abandoning an uncertain workspace creation', async () => {
        fetchMock.mockRejectedValueOnce(
            new TypeError('Synthetic network loss'),
        );
        respond(201, { data: { web_url: '/synthetic/reopened-workspace' } });
        openCreate();
        await screen.findByRole('alert');
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('dialog')).toBeVisible();
        expect(screen.getByLabelText('Name', { exact: true })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Check creation' }));
        await waitFor(() =>
            expect(inertia.visit).toHaveBeenCalledWith(
                '/synthetic/reopened-workspace',
                expect.any(Object),
            ),
        );
        expect(request(0)).toEqual(request(1));
    });
});

it.each([
    'onCancel',
    'onError',
    'onNetworkError',
    'onHttpException',
    'onFinish',
] as const)(
    'recovers a created workspace after navigation %s without another create',
    async (callback) => {
        inertia.visit.mockImplementationOnce((_url, options) => {
            const handlers = options as unknown as Record<
                string,
                () => boolean | void
            >;
            const result = handlers[callback]();

            if (
                callback === 'onNetworkError' ||
                callback === 'onHttpException'
            ) {
                expect(result).toBe(false);
            }
        });
        respond(201, {
            data: { web_url: '/synthetic/already-created-workspace' },
        });
        openCreate();
        await screen.findByText(
            /workspace was created, but its page could not open/,
        );
        expect(screen.getByLabelText('Name', { exact: true })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        const recovery = screen.getByRole('button', {
            name: 'Open created workspace',
        });
        expect(recovery).toBeEnabled();
        fireEvent.click(recovery);
        await waitFor(() => expect(inertia.visit).toHaveBeenCalledTimes(2));
        expect(fetchMock).toHaveBeenCalledOnce();
        expect(inertia.visit.mock.calls[0][0]).toBe(
            '/synthetic/already-created-workspace',
        );
        expect(inertia.visit.mock.calls[1][0]).toBe(
            '/synthetic/already-created-workspace',
        );
    },
);
