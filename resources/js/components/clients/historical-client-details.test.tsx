import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ClientContacts } from '@/components/clients/client-contacts';
import { CompanyProfile } from '@/components/clients/company-profile';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const writes = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (field: string, value: unknown) =>
                    setData((prior) => ({ ...prior, [field]: value })),
                patch: (url: string) => writes.patch(url, data),
                post: (url: string) => writes.post(url, data),
                reset: () => setData(initial),
            };
        },
    };
});

describe('historical client details', () => {
    it('edits company facts using the supplied destination without billing or access controls', () => {
        render(
            <CompanyProfile
                action="/synthetic/profile-destination"
                profile={{
                    billing_address: 'Synthetic address',
                    phone: null,
                    website_url: null,
                    tax_id: null,
                    currency: null,
                    notes: null,
                }}
            />,
        );
        fireEvent.change(screen.getByLabelText('Preferred currency'), {
            target: { value: 'usd' },
        });
        fireEvent.change(screen.getByLabelText('Internal company notes'), {
            target: { value: 'Synthetic note' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save company profile' }),
        );
        expect(writes.patch).toHaveBeenLastCalledWith(
            '/synthetic/profile-destination',
            {
                billing_address: 'Synthetic address',
                phone: '',
                website_url: '',
                tax_id: '',
                currency: 'USD',
                notes: 'Synthetic note',
            },
        );
    });

    it('adds a named contact without inviting them or inferring any access', () => {
        render(
            <ClientContacts
                contacts={[]}
                createAction="/synthetic/contact-destination"
            />,
        );
        expect(
            screen.getByText(
                /does not grant portal access or send an invitation/,
            ),
        ).toBeVisible();
        fireEvent.change(screen.getByLabelText('First name'), {
            target: { value: 'Casey' },
        });
        fireEvent.change(screen.getByLabelText('Email'), {
            target: { value: 'casey@example.test' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add contact' }));
        expect(writes.post).toHaveBeenLastCalledWith(
            '/synthetic/contact-destination',
            {
                first_name: 'Casey',
                last_name: '',
                title: '',
                email: 'casey@example.test',
                phone: '',
                notes: '',
                is_archived: false,
                lock_version: 1,
            },
        );
    });

    it('keeps archived contacts visible and wraps oversized imported names and notes', () => {
        const { container } = render(
            <ClientContacts
                createAction="/synthetic/new"
                contacts={[
                    {
                        id: 'synthetic-contact',
                        first_name: 'SyntheticContactName'.repeat(10),
                        last_name: 'Example',
                        title: 'SyntheticTitle'.repeat(12),
                        email: 'casey@example.test',
                        phone: '555-0100',
                        notes: 'SyntheticHistoricalNote'.repeat(30),
                        is_archived: true,
                        lock_version: 7,
                        update_action: '/synthetic/contact-edit',
                    },
                ]}
            />,
        );
        expect(screen.getByText('Archived')).toBeVisible();
        fireEvent.click(container.querySelector('summary')!);
        const button = screen.getByRole('button', { name: 'Save contact' });
        const form = button.closest('form')!;
        fireEvent.change(within(form).getByLabelText('Role or title'), {
            target: { value: 'Revised role' },
        });
        fireEvent.click(button);
        expect(writes.patch).toHaveBeenLastCalledWith(
            '/synthetic/contact-edit',
            expect.objectContaining({
                title: 'Revised role',
                is_archived: true,
                lock_version: 7,
            }),
        );
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
