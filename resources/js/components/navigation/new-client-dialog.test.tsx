import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NewClientDialog } from '@/components/navigation/new-client-dialog';
import { apiRequest } from '@/lib/api';

const visit = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({ router: { visit } }));
vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(),
    newIdempotencyKey: () => crypto.randomUUID(),
}));
beforeEach(() => vi.clearAllMocks());

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
                data: { id: 'opaque-client' },
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
        fireEvent.click(screen.getByRole('button', { name: 'Add client' }));
        await waitFor(() =>
            expect(visit).toHaveBeenCalledWith('/synthetic/new-client-home'),
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
        vi.mocked(apiRequest).mockResolvedValue({
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
    });
});
