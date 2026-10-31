import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NewClientDialog } from '@/components/navigation/new-client-dialog';
import { apiRequest } from '@/lib/api';

const visit = vi.hoisted(() => vi.fn());
const beforeNavigation = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({
    router: { visit, on: beforeNavigation },
}));
vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(),
    newIdempotencyKey: () => crypto.randomUUID(),
}));
beforeEach(() => {
    vi.clearAllMocks();
    beforeNavigation.mockReturnValue(vi.fn());
    vi.mocked(apiRequest).mockReset();
    visit.mockReset();
    visit.mockImplementation((_url, options) => {
        options.onSuccess();
        options.onFinish();
    });
});

describe('new client API creation', () => {
    it('retries an uncertain creation with the same key and follows the finished Location', async () => {
        vi.mocked(apiRequest)
            .mockResolvedValueOnce({
                ok: false,
                status: 0,
                message: 'Synthetic connection failure',
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                data: { id: 'opaque-client', version: 'created-version' },
                location: '/synthetic/new-client-home',
            });
        const close = vi.fn();
        render(
            <NewClientDialog
                open
                action="/synthetic/create-client"
                onOpenChange={close}
            />,
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic New Client' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Synthetic connection failure',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original change' }),
        );
        await waitFor(() =>
            expect(visit).toHaveBeenCalledWith(
                '/synthetic/new-client-home',
                expect.any(Object),
            ),
        );
        expect(apiRequest).toHaveBeenNthCalledWith(
            1,
            'POST',
            '/synthetic/create-client',
            { name: 'Synthetic New Client', billing_email: null },
            expect.any(String),
        );
        expect(vi.mocked(apiRequest).mock.calls[0][3]).toBe(
            vi.mocked(apiRequest).mock.calls[1][3],
        );
        expect(close).toHaveBeenCalledWith(false);
    });

    it('keeps validation refusals and entered values visible', async () => {
        vi.mocked(apiRequest).mockResolvedValueOnce({
            ok: false,
            status: 422,
            message: 'Choose a client name.',
            errors: { name: ['Choose a client name.'] },
        });
        render(
            <NewClientDialog
                open
                action="/synthetic/create-client"
                onOpenChange={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Retained synthetic name' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Choose a client name.',
        );
        expect(screen.getByLabelText('Name')).toHaveValue(
            'Retained synthetic name',
        );
        expect(visit).not.toHaveBeenCalled();
        const refusedKey = vi.mocked(apiRequest).mock.calls[0][3];
        vi.mocked(apiRequest).mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: { version: 'corrected-version' },
            location: '/synthetic/corrected-client',
        });
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Corrected synthetic name' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
        await waitFor(() => expect(visit).toHaveBeenCalled());
        expect(vi.mocked(apiRequest).mock.calls[1][2]).toEqual({
            name: 'Corrected synthetic name',
            billing_email: null,
        });
        expect(vi.mocked(apiRequest).mock.calls[1][3]).not.toBe(refusedKey);
    });
    it('keeps an unknown client creation through closing, reopening and a later refusal', async () => {
        vi.mocked(apiRequest)
            .mockResolvedValueOnce({
                ok: false,
                status: 0,
                message: 'Synthetic lost answer',
            })
            .mockResolvedValueOnce({
                ok: false,
                status: 403,
                message: 'Synthetic current access refusal',
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                data: { version: 'created-version' },
                location: '/synthetic/client-home',
            });
        const close = vi.fn();
        const view = render(
            <NewClientDialog
                open
                action="/synthetic/original-client-create"
                onOpenChange={close}
            />,
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic original client' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
        await screen.findByText('Synthetic lost answer');
        const original = vi.mocked(apiRequest).mock.calls[0];
        expect(screen.getByLabelText('Name')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeEnabled();
        await userEvent.keyboard('{Escape}');
        expect(close).toHaveBeenCalledWith(false);
        view.rerender(
            <NewClientDialog
                open={false}
                action="/synthetic/changed-client-create"
                onOpenChange={close}
            />,
        );
        view.rerender(
            <NewClientDialog
                open
                action="/synthetic/changed-client-create"
                onOpenChange={close}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original change' }),
        );
        await screen.findByText('Synthetic current access refusal');
        expect(vi.mocked(apiRequest).mock.calls[1]).toEqual(original);
        expect(screen.getByLabelText('Name')).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original change' }),
        );
        await waitFor(() => expect(visit).toHaveBeenCalled());
        expect(vi.mocked(apiRequest).mock.calls[2]).toEqual(original);
    });

    it('keeps the original creation when a successful response has no finished destination', async () => {
        vi.mocked(apiRequest)
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                data: { version: 'created-version' },
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                data: { version: 'created-version' },
                location: '/synthetic/recovered-client',
            });
        render(
            <NewClientDialog
                open
                action="/synthetic/create-client"
                onOpenChange={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic unread destination' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
        await screen.findByText(
            'SVC’s response could not be read. Check the original change.',
        );
        expect(visit).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Name')).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original change' }),
        );
        await waitFor(() => expect(visit).toHaveBeenCalled());
        expect(vi.mocked(apiRequest).mock.calls[1]).toEqual(
            vi.mocked(apiRequest).mock.calls[0],
        );
    });

    it('retains a created client when another pending change prevents its destination visit', async () => {
        vi.mocked(apiRequest).mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: { version: 'created-version' },
            location: '/synthetic/created-client',
        });
        visit.mockImplementationOnce(() => {
            const event = new Event('inertia:before', { cancelable: true });
            Object.defineProperty(event, 'detail', {
                value: { visit: { url: '/synthetic/created-client' } },
            });
            event.preventDefault();
            beforeNavigation.mock.calls.at(-1)?.[1](event);
            // A canceled global before event has no later Inertia callbacks.
        });
        const close = vi.fn();
        const view = render(
            <NewClientDialog
                open
                action="/synthetic/create-client"
                onOpenChange={close}
            />,
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic blocked client' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
        await screen.findByText(
            'The change was recorded, but this page could not load it. Check the original change.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(close).toHaveBeenCalledWith(false);
        view.rerender(
            <NewClientDialog
                open={false}
                action="/synthetic/changed-url"
                onOpenChange={close}
            />,
        );
        view.rerender(
            <NewClientDialog
                open
                action="/synthetic/changed-url"
                onOpenChange={close}
            />,
        );
        expect(screen.getByLabelText('Name')).toHaveValue(
            'Synthetic blocked client',
        );
        expect(screen.getByLabelText('Name')).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original change' }),
        );
        await waitFor(() => expect(visit).toHaveBeenCalledTimes(2));
        expect(visit.mock.calls[1][0]).toBe('/synthetic/created-client');
        expect(apiRequest).toHaveBeenCalledOnce();
    });

    it('retries only the finished client destination after network or HTTP navigation failure', async () => {
        vi.mocked(apiRequest).mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: { version: 'created-version' },
            location: '/synthetic/created-client',
        });
        visit
            .mockImplementationOnce((_url, options) => {
                expect(
                    options.onNetworkError(
                        new Error('Synthetic network failure'),
                    ),
                ).toBe(false);
                options.onFinish();
            })
            .mockImplementationOnce((_url, options) => {
                expect(options.onHttpException({ status: 503 })).toBe(false);
                options.onFinish();
            });
        const close = vi.fn();
        render(
            <NewClientDialog
                open
                action="/synthetic/create-client"
                onOpenChange={close}
            />,
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic recorded client' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
        await screen.findByText(
            'The change was recorded, but this page could not load it. Check the original change.',
        );
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original change' }),
        );
        await waitFor(() => expect(visit).toHaveBeenCalledTimes(2));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check original change' }),
            ).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original change' }),
        );
        await waitFor(() => expect(close).toHaveBeenCalledWith(false));
        expect(apiRequest).toHaveBeenCalledTimes(1);
        expect(visit).toHaveBeenCalledTimes(3);
        expect(visit.mock.calls[2][0]).toBe('/synthetic/created-client');
    });
});
