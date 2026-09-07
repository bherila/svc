import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AgreementActivationButton, PaymentForm } from '@/pages/operations';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

/**
 * A stub that keeps the part of `useForm` this file is about: its lifetime.
 *
 * Inertia's form survives its own submission - the component stays mounted
 * across the redirect and the data it holds is whatever the last render left
 * there - which is precisely the behaviour a naive stub hides. So `setData`
 * writes, `reset` restores the values captured at mount rather than today's,
 * and `post` runs the success callback the way a 302 back to this page does.
 */
const form = vi.hoisted(() => ({
    data: {} as Record<string, unknown>,
    initial: null as Record<string, unknown> | null,
    errors: {} as Record<string, string>,
    processing: false,
    setData: vi.fn((key: string, value: unknown) => {
        form.data[key] = value;
    }),
    transform: vi.fn(),
    post: vi.fn((_href: string, options?: { onSuccess?: () => void }) =>
        options?.onSuccess?.(),
    ),
    reset: vi.fn((...keys: string[]) => {
        for (const key of keys) {
            form.data[key] = form.initial?.[key];
        }
    }),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
    useForm: (initial: Record<string, unknown>) => {
        // Captured once, like the real hook: the initial values are the ones
        // the page loaded with, not the ones the clock says on a later render.
        if (form.initial === null) {
            form.initial = { ...initial };
            form.data = { ...initial };
        }

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
        form.initial = null;
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
        const { rerender } = render(
            <PaymentForm
                workspaceId="workspace-1"
                invoice={invoice()}
                timezone="America/Los_Angeles"
            />,
        );

        fireEvent.change(screen.getByLabelText('Payment received on'), {
            target: { value: '2026-07-01' },
        });
        rerender(
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
     * A backdate must not outlive the payment it was typed for.
     *
     * Inertia keeps this form mounted across the redirect, so the date an
     * operator entered to record last Tuesday's cheque stayed in the field, and
     * the next payment recorded in the same sitting carried it. That is the
     * same wrong-period defect the field exists to fix, arrived at from the
     * other side and quieter: a stale date reads exactly like the default it
     * replaced, so there is nothing to notice.
     *
     * The clock is moved on between the two payments, which is what separates
     * the fix from `form.reset('received_on')` - reset restores the value
     * captured when the page loaded, and this screen can sit open overnight.
     */
    it('returns the date to today after a payment is recorded', () => {
        const screenProps = {
            workspaceId: 'workspace-1',
            invoice: invoice(),
            timezone: 'America/Los_Angeles',
        };
        const { container, rerender } = render(
            <PaymentForm {...screenProps} />,
        );

        fireEvent.change(screen.getByLabelText('Payment received on'), {
            target: { value: '2026-08-10' },
        });
        expect(form.data.received_on).toBe('2026-08-10');

        // The page has been open past its own midnight, twice.
        vi.setSystemTime(new Date('2026-09-02T12:00:00Z'));
        fireEvent.submit(container.querySelector('form') as HTMLFormElement);

        expect(form.data.received_on).toBe('2026-09-02');
        rerender(<PaymentForm {...screenProps} />);
        expect(screen.getByLabelText('Payment received on')).toHaveValue(
            '2026-09-02',
        );

        // The reference is cleared and the retry key replaced, as before: this
        // adds the date to that set rather than replacing it.
        expect(form.data.reference).toBe('');
        expect(form.data.idempotency_key).not.toBe(
            form.initial?.idempotency_key,
        );
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
