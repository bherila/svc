import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientInvoices from '@/pages/clients/invoices';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { reload: vi.fn() },
    useForm: () => ({
        data: {
            invoice_number: '',
            due_date: '',
            currency: 'USD',
            description: '',
            quantity: '1',
            unit_price: '',
        },
        errors: {},
        processing: false,
        setData: vi.fn(),
    }),
}));

vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

const company = { id: 'company-1', name: 'Synthetic Client' };
const base = '/workspaces/workspace-1/clients/company-1/invoices';

describe('client invoices page draft form', () => {
    it('offers the draft form only with the server capability', () => {
        const { rerender } = render(
            <ClientInvoices
                company={company}
                invoice_base_href={base}
                invoice_create_href="/api/v1/workspaces/workspace-1/invoices"
                invoices={[]}
            />,
        );

        expect(
            screen.getByRole('form', { name: 'Draft invoice' }),
        ).toBeInTheDocument();

        rerender(
            <ClientInvoices
                company={company}
                invoice_base_href={base}
                invoice_create_href={null}
                invoices={[]}
            />,
        );
        expect(
            screen.queryByRole('form', { name: 'Draft invoice' }),
        ).toBeNull();

        // The client's own copy of the page never sends the key at all.
        rerender(
            <ClientInvoices
                company={company}
                invoice_base_href={base}
                invoices={[]}
            />,
        );
        expect(
            screen.queryByRole('form', { name: 'Draft invoice' }),
        ).toBeNull();
    });
});
