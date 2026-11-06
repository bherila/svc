import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { InvoiceForm } from '@/components/billing/invoice-draft-form';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(
        (options: { onSuccess?: () => void; onFinish?: () => void }) => {
            options.onSuccess?.();
            options.onFinish?.();
        },
    ),
}));
const requestFetch = vi.fn();

beforeEach(() => {
    requestFetch.mockReset();
    requestFetch.mockImplementation(
        async () => new Response(JSON.stringify({ data: {} }), { status: 200 }),
    );
    vi.stubGlobal('fetch', requestFetch);
});

afterEach(() => vi.unstubAllGlobals());

/** Keeps the part of `useForm` that matters here: its lifetime across a submit. */
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
        if (keys.length === 0) {
            keys = Object.keys(form.initial ?? {});
        }

        for (const key of keys) {
            form.data[key] = form.initial?.[key];
        }
    }),
}));

vi.mock('@inertiajs/react', async () => {
    const { useRef } = await import('react');

    return {
        router: inertia,
        useForm: (initial: Record<string, unknown>) => {
            const initialized = useRef(false);

            if (!initialized.current) {
                initialized.current = true;
                form.initial = { ...initial };
                form.data = { ...initial };
            }

            return form;
        },
    };
});

describe('drafting an invoice on the client invoices page', () => {
    beforeEach(() => {
        form.data = {};
        form.initial = null;
        form.errors = {};
    });

    it('uses the finished capability and retains its attempt key and values after a lost response', async () => {
        requestFetch.mockRejectedValueOnce(
            new TypeError('Synthetic lost response'),
        );
        render(
            <InvoiceForm
                href="/synthetic/invoice-create"
                clientId="synthetic-company"
            />,
        );
        fireEvent.change(screen.getByPlaceholderText('Invoice number'), {
            target: { value: 'SYNTHETIC-CREATED' },
        });
        fireEvent.change(screen.getByPlaceholderText('Line description'), {
            target: { value: 'Synthetic service' },
        });
        fireEvent.change(screen.getByPlaceholderText('Unit price'), {
            target: { value: '123.45' },
        });
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await screen.findByRole('alert');
        expect(screen.getByPlaceholderText('Invoice number')).toHaveValue(
            'SYNTHETIC-CREATED',
        );
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(requestFetch.mock.calls).toHaveLength(2);
        const first = requestFetch.mock.calls[0];
        const second = requestFetch.mock.calls[1];
        expect(first[0]).toBe('/synthetic/invoice-create');
        expect(JSON.parse(first[1].body)).toEqual({
            company_id: 'synthetic-company',
            invoice_number: 'SYNTHETIC-CREATED',
            due_date: null,
            currency: 'USD',
            is_visible_to_client: false,
            manual_lines: [
                {
                    type: 'service',
                    description: 'Synthetic service',
                    quantity: '1',
                    unit_amount: 12345,
                    tax_amount: 0,
                },
            ],
        });
        expect(first[1].headers['Idempotency-Key']).toBe(
            second[1].headers['Idempotency-Key'],
        );
        expect(first[1].body).toBe(second[1].body);
    });
});

describe('invoice draft recovery and edit baselines', () => {
    beforeEach(() => {
        form.data = {};
        form.initial = null;
        form.errors = {};
        inertia.reload.mockImplementation(
            (options: { onSuccess?: () => void; onFinish?: () => void }) => {
                options.onSuccess?.();
                options.onFinish?.();
            },
        );
    });
    function failure(status: number) {
        return new Response(JSON.stringify({ message: 'Synthetic refusal' }), {
            status,
        });
    }
    function request(index: number) {
        const [url, init] = requestFetch.mock.calls[index];

        return {
            url,
            method: init.method,
            body: init.body,
            key: init.headers['Idempotency-Key'],
        };
    }
    it('keeps an unknown draft intact across editing/context changes and only refreshes after known save', async () => {
        requestFetch.mockResolvedValueOnce(failure(408));
        const { rerender } = render(
            <InvoiceForm href="/original-drafts" clientId="original-company" />,
        );
        fireEvent.change(screen.getByPlaceholderText('Invoice number'), {
            target: { value: 'SYN-ORIGINAL' },
        });
        fireEvent.change(screen.getByPlaceholderText('Line description'), {
            target: { value: 'Original work' },
        });
        fireEvent.change(screen.getByPlaceholderText('Unit price'), {
            target: { value: '25.00' },
        });
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await screen.findByRole('button', { name: 'Check original request' });
        expect(
            screen.getByRole('button', { name: 'Reset draft form' }),
        ).toBeDisabled();
        expect(screen.getByPlaceholderText('Invoice number')).toBeDisabled();
        rerender(<InvoiceForm href={null} clientId="different-company" />);
        form.data.invoice_number = 'Edited after unknown';
        requestFetch.mockResolvedValueOnce(failure(422));
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
        await vi.waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check original request' }),
            ).toBeEnabled(),
        );
        inertia.reload.mockImplementation(
            (options: { onFinish?: () => void }) => options.onFinish?.(),
        );
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await screen.findByText(
            /The change was saved, but the page could not refresh/,
        );
        expect(requestFetch).toHaveBeenCalledTimes(3);
        expect(request(2)).toEqual(request(0));
        expect(form.data.invoice_number).toBe('Edited after unknown');
        inertia.reload.mockImplementation(
            (options: { onSuccess?: () => void; onFinish?: () => void }) => {
                options.onSuccess?.();
                options.onFinish?.();
            },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh saved result' }),
        );
        await vi.waitFor(() =>
            expect(
                screen.queryByRole('form', { name: 'Draft invoice' }),
            ).toBeNull(),
        );
        expect(requestFetch).toHaveBeenCalledTimes(3);
    });
});
