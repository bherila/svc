import { useForm } from '@inertiajs/react';
import { PlusIcon, RotateCcwIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { useInvoiceMutation } from '@/hooks/use-invoice-mutation';
import type { ApiSchema } from '@/lib/api';
import { toMinorUnits } from '@/lib/money';

const inputClass =
    'w-full min-w-0 rounded-xl border border-border bg-card px-3 py-2 text-sm text-card-foreground outline-none focus:border-cyan-600 focus-visible:ring-2 focus-visible:ring-cyan-600/50';

/**
 * The draft-invoice form, on the client's Invoices page.
 *
 * Moved from the classic Operations page with its behaviour intact: the finished
 * `href` is the server's capability (absent for a viewer who may not draft),
 * the write keeps one idempotency key and one body across an uncertain
 * response, and the form stays locked until the original request is verified.
 */
export function InvoiceForm({
    href,
    clientId,
}: {
    href: string | null;
    clientId: string;
}) {
    const [generation, setGeneration] = useState(0);
    const [available, setAvailable] = useState(href !== null);

    if (!available && href !== null) {
        setAvailable(true);
    }

    if (!available) {
        return null;
    }

    return (
        <InvoiceDraft
            key={generation}
            href={href}
            clientId={clientId}
            onDone={() => {
                setAvailable(false);
                setGeneration((current) => current + 1);
            }}
        />
    );
}

function InvoiceDraft({
    href,
    clientId,
    onDone,
}: {
    href: string | null;
    clientId: string;
    onDone: () => void;
}) {
    const mutation = useInvoiceMutation();
    const [baseline] = useState({ href, clientId });
    const [validationError, setValidationError] = useState<string | null>(null);
    const form = useForm({
        invoice_number: '',
        due_date: '',
        currency: 'USD',
        description: '',
        quantity: '1',
        unit_price: '',
    });

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (mutation.isLocked()) {
            await mutation.retry();

            return;
        }

        if (baseline.href === null) {
            return;
        }

        const amount = toMinorUnits(form.data.unit_price);

        if (amount === null) {
            setValidationError('Enter a valid unit price.');

            return;
        }

        const body = {
            company_id: baseline.clientId,
            invoice_number: form.data.invoice_number,
            due_date: form.data.due_date || null,
            currency: form.data.currency,
            is_visible_to_client: false,
            manual_lines: [
                {
                    type: 'service',
                    description: form.data.description,
                    quantity: form.data.quantity,
                    unit_amount: amount,
                    tax_amount: 0,
                },
            ],
        } satisfies ApiSchema<'InvoiceCreateRequest'>;
        setValidationError(null);
        await mutation.submit('POST', baseline.href, body, onDone);
    };

    if (href === null && !mutation.locked) {
        return null;
    }

    const error = mutation.error ?? validationError;

    return (
        <form
            aria-label="Draft invoice"
            onSubmit={submit}
            className="grid grid-cols-1 gap-2 md:grid-cols-6"
        >
            <input
                disabled={mutation.locked}
                className={inputClass}
                placeholder="Invoice number"
                aria-label="Invoice number"
                value={form.data.invoice_number}
                onChange={(event) =>
                    form.setData('invoice_number', event.target.value)
                }
                required
            />
            <input
                disabled={mutation.locked}
                className={inputClass}
                type="date"
                aria-label="Due date"
                value={form.data.due_date}
                onChange={(event) =>
                    form.setData('due_date', event.target.value)
                }
            />
            <input
                disabled={mutation.locked}
                className={`${inputClass} md:col-span-2`}
                placeholder="Line description"
                aria-label="Line description"
                value={form.data.description}
                onChange={(event) =>
                    form.setData('description', event.target.value)
                }
                required
            />
            <input
                disabled={mutation.locked}
                className={inputClass}
                type="number"
                min="0"
                step="0.01"
                placeholder="Unit price"
                aria-label="Unit price"
                value={form.data.unit_price}
                onChange={(event) =>
                    form.setData('unit_price', event.target.value)
                }
                required
            />
            {error !== null && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive md:col-span-6"
                >
                    {error}
                </p>
            )}
            <Button type="submit" disabled={mutation.busy}>
                <PlusIcon aria-hidden />
                {mutation.saved
                    ? 'Refresh saved result'
                    : mutation.locked
                      ? 'Check original request'
                      : 'Draft invoice'}
            </Button>
            <Button
                type="button"
                variant="outline"
                disabled={mutation.locked}
                onClick={onDone}
            >
                <RotateCcwIcon aria-hidden />
                Reset draft form
            </Button>
        </form>
    );
}
