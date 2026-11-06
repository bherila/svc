import { useForm } from '@inertiajs/react';
import { PlusIcon, RotateCcwIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useInvoiceMutation } from '@/hooks/use-invoice-mutation';
import type { ApiSchema } from '@/lib/api';
import { toMinorUnits } from '@/lib/money';

/**
 * The draft-invoice form, on the client's Invoices page.
 *
 * Moved from the retired Operations page with its behaviour intact: the finished
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
            className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6"
        >
            <FormField label="Invoice number">
                {(id) => (
                    <Input
                        id={id}
                        disabled={mutation.locked}
                        value={form.data.invoice_number}
                        onChange={(event) =>
                            form.setData('invoice_number', event.target.value)
                        }
                        required
                    />
                )}
            </FormField>
            <FormField label="Due date">
                {(id) => (
                    <Input
                        id={id}
                        disabled={mutation.locked}
                        type="date"
                        value={form.data.due_date}
                        onChange={(event) =>
                            form.setData('due_date', event.target.value)
                        }
                    />
                )}
            </FormField>
            <FormField label="Line description" className="sm:col-span-2">
                {(id) => (
                    <Input
                        id={id}
                        disabled={mutation.locked}
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        required
                    />
                )}
            </FormField>
            <FormField label="Unit price (USD)">
                {(id) => (
                    <Input
                        id={id}
                        disabled={mutation.locked}
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.data.unit_price}
                        onChange={(event) =>
                            form.setData('unit_price', event.target.value)
                        }
                        required
                    />
                )}
            </FormField>
            {error !== null && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive sm:col-span-2 lg:col-span-6"
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
