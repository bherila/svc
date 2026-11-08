import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WebhookHealth } from '@/components/workspaces/webhook-health';
import type { WebhookHealthView } from '@/components/workspaces/webhook-health';

const mocks = vi.hoisted(() => ({
    reload: vi.fn(),
    apiRequest: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ router: { reload: mocks.reload } }));
vi.mock('@/lib/api', () => ({ apiRequest: mocks.apiRequest }));

function health(overrides: Partial<WebhookHealthView> = {}): WebhookHealthView {
    return {
        severity: 'ok',
        summary: 'Webhook: all 19 events subscribed',
        detail: null,
        missing: [],
        endpoint_url: 'https://svc.example.test/api/webhooks/stripe/token',
        checked_at: '2026-11-07T10:00:00Z',
        check_url: '/synthetic/check',
        fix: null,
        ...overrides,
    };
}

describe('Stripe webhook health', () => {
    beforeEach(() => {
        mocks.reload.mockReset();
        mocks.apiRequest.mockReset();
        mocks.apiRequest.mockResolvedValue({
            ok: true,
            status: 204,
            data: null,
        });
    });

    it('says everything is subscribed and checks again on request', async () => {
        render(<WebhookHealth health={health()} />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'Webhook: all 19 events subscribed',
        );
        expect(screen.getByText(/Checked/)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Add missing events' }),
        ).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Check again' }));

        await waitFor(() => expect(mocks.reload).toHaveBeenCalled());
        expect(mocks.apiRequest).toHaveBeenCalledWith(
            'POST',
            '/synthetic/check',
            {},
        );
    });

    it('warns about missing events and fixes only after the confirmation', async () => {
        mocks.apiRequest.mockResolvedValue({
            ok: true,
            status: 200,
            data: { message: 'Added 1 missing event to the Stripe webhook.' },
        });
        render(
            <WebhookHealth
                health={health({
                    severity: 'warning',
                    summary: 'Missing: payment_method.attached',
                    missing: ['payment_method.attached'],
                    fix: {
                        action: 'add_events',
                        label: 'Add missing events',
                        confirm_title: 'Add the missing events?',
                        confirm_body: 'SVC will subscribe the endpoint.',
                        url: '/synthetic/fix',
                    },
                })}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Missing: payment_method.attached',
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Add missing events' }),
        );

        expect(mocks.apiRequest).not.toHaveBeenCalled();
        expect(screen.getByText('Add the missing events?')).toBeInTheDocument();

        const buttons = screen.getAllByRole('button', {
            name: 'Add missing events',
        });

        fireEvent.click(buttons[buttons.length - 1]);

        await waitFor(() =>
            expect(mocks.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/synthetic/fix',
                { action: 'add_events' },
            ),
        );
        await waitFor(() => expect(mocks.reload).toHaveBeenCalled());
    });

    it('shows no buttons or URL to someone who cannot manage the connection', () => {
        render(
            <WebhookHealth
                health={health({ check_url: null, endpoint_url: null })}
            />,
        );

        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByText(/api\/webhooks/)).toBeNull();
    });

    it('shows a refusal from the server', async () => {
        mocks.apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'The given data was invalid.',
            errors: { webhook: ['Set Webhook Endpoints to Write.'] },
        });
        render(
            <WebhookHealth
                health={health({
                    severity: 'warning',
                    summary: 'No webhook endpoint found for this connection',
                    fix: {
                        action: 'create',
                        label: 'Create endpoint',
                        confirm_title: 'Create the endpoint?',
                        confirm_body: 'SVC will create it.',
                        url: '/synthetic/fix',
                    },
                })}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Create endpoint' }),
        );

        const buttons = screen.getAllByRole('button', {
            name: 'Create endpoint',
        });

        fireEvent.click(buttons[buttons.length - 1]);

        expect(
            await screen.findByText('Set Webhook Endpoints to Write.'),
        ).toBeInTheDocument();
        expect(mocks.reload).not.toHaveBeenCalled();
    });
});
