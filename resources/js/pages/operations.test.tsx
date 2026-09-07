import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AgreementActivationButton, PaymentForm } from '@/pages/operations';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));
const form = vi.hoisted(() => ({
    data: {} as Record<string, unknown>,
    errors: {} as Record<string, string>,
    processing: false,
    setData: vi.fn(),
    transform: vi.fn(),
    post: vi.fn(),
    reset: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
    useForm: (initial: Record<string, unknown>) => {
        form.data = { ...initial, ...form.data };

        return form;
    },
}));

function invoice(overrides: Record<string, unknown> = {}) {
    return {
        id: 'invoice-1',
        invoice_number: 'INV-SYNTH-1',
        status: 'issued',
        total_amount: 10000,
        paid_amount: 0,
        balance_amount: 10000,
        currency: 'USD',
        issue_date: '2026-08-15',
        due_date: '2026-09-14',
        attachments: [],
        ...overrides,
    };
}

describe('agreement activation', () => {
    it('shows an overlap refusal beside the activation control', () => {
        inertia.post.mockImplementation(
            (
                _href: string,
                _data: undefined,
                options: { onError: (errors: Record<string, string>) => void },
            ) => {
                options.onError({
                    engagement:
                        'This agreement cannot overlap another active agreement. Ask an operator to verify its terms.',
                });
            },
        );

        render(<AgreementActivationButton href="/agreements/one/activate" />);
        fireEvent.click(screen.getByRole('button', { name: 'Activate' }));

        expect(screen.getByRole('alert')).toHaveTextContent(
            'This agreement cannot overlap another active agreement. Ask an operator to verify its terms.',
        );
    });
});

/**
 * This form has always held `received_on`, defaulted it and posted it. It never
 * rendered it, so the value an operator could not see was the day they happened
 * to be typing on - and it is the column an external finance system
 * reconciles by.
 */
describe('recording a payment from the operations screen', () => {
    beforeEach(() => {
        form.data = {};
        form.errors = {};
        // 05:30 UTC on the 30th is the 29th in Los Angeles: the default is the
        // workspace's day rather than UTC's.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-08-30T05:30:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the date it was already sending, on the workspace calendar', () => {
        render(
            <PaymentForm
                workspaceId="workspace-1"
                invoice={invoice()}
                timezone="America/Los_Angeles"
            />,
        );

        const received = screen.getByLabelText('Payment received on');
        expect(received).toHaveValue('2026-08-29');

        fireEvent.change(received, { target: { value: '2026-08-24' } });
        expect(form.setData).toHaveBeenCalledWith('received_on', '2026-08-24');
    });

    it('warns about a date before the invoice was issued without blocking it', () => {
        form.data = { received_on: '2026-07-01' };
        render(
            <PaymentForm
                workspaceId="workspace-1"
                invoice={invoice()}
                timezone="America/Los_Angeles"
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'This payment is dated before the invoice was issued on Aug 15, 2026.',
        );
        expect(
            screen.getByRole('button', { name: 'Record payment' }),
        ).toBeEnabled();
    });

    /**
     * A bounded date is refused by the service as a `billing` error, and this
     * form used to drop every one of them: the operator pressed the button and
     * the screen sat there.
     */
    it('shows the service refusal it used to swallow', () => {
        form.errors = {
            billing:
                'A payment cannot be dated before 2024-08-29. Nothing has been changed.',
        };
        render(
            <PaymentForm
                workspaceId="workspace-1"
                invoice={invoice()}
                timezone="America/Los_Angeles"
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'A payment cannot be dated before 2024-08-29.',
        );
    });
});
