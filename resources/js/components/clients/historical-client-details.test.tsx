import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ClientContacts } from '@/components/clients/client-contacts';
import { CompanyProfile } from '@/components/clients/company-profile';
import { apiRequest } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(),
    newIdempotencyKey: () => crypto.randomUUID(),
}));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn(async () => {}),
}));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = useState(initial);

            return {
                data,
                setData: (field: string, value: unknown) =>
                    setData((prior) => ({ ...prior, [field]: value })),
                reset: () => setData(initial),
            };
        },
    };
});

const profile = {
    billing_address: 'Synthetic address',
    phone: null,
    website_url: null,
    tax_id: null,
    currency: null,
    notes: null,
};
const contact = {
    id: 'synthetic-contact',
    first_name: 'SyntheticContactName'.repeat(10),
    last_name: 'Example',
    title: 'SyntheticTitle'.repeat(12),
    email: 'casey@example.test',
    phone: '555-0100',
    notes: 'SyntheticHistoricalNote'.repeat(30),
    is_archived: true,
    version: 'contact-version',
    update_action: '/synthetic/contact-edit',
    archive_action: '/synthetic/contact-archive',
    restore_action: '/synthetic/contact-restore',
};

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(apiRequest).mockResolvedValue({
        ok: true,
        status: 200,
        data: {},
    });
});

describe('historical client details', () => {
    it('edits only company facts through the supplied API destination and current version', async () => {
        render(
            <CompanyProfile
                action="/synthetic/profile-destination"
                profile={profile}
                version="company-version"
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
        await waitFor(() => expect(refreshPageProps).toHaveBeenCalled());
        expect(apiRequest).toHaveBeenLastCalledWith(
            'PATCH',
            '/synthetic/profile-destination',
            {
                billing_address: 'Synthetic address',
                phone: null,
                website_url: null,
                tax_id: null,
                currency: 'USD',
                notes: 'Synthetic note',
                expected_version: 'company-version',
            },
            expect.any(String),
        );
    });

    it('adds a named contact without login or invitation fields', async () => {
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
        await waitFor(() =>
            expect(refreshPageProps).toHaveBeenCalledWith({
                only: ['contacts'],
            }),
        );
        expect(apiRequest).toHaveBeenLastCalledWith(
            'POST',
            '/synthetic/contact-destination',
            {
                first_name: 'Casey',
                last_name: null,
                title: null,
                email: 'casey@example.test',
                phone: null,
                notes: null,
                is_archived: false,
            },
            expect.any(String),
        );
    });

    it('keeps archived contacts visible and edits details without changing lifecycle state', async () => {
        const { container } = render(
            <ClientContacts
                createAction="/synthetic/new"
                contacts={[contact]}
            />,
        );
        expect(screen.getByText('Archived')).toBeVisible();
        fireEvent.click(container.querySelector('summary')!);
        const button = screen.getByRole('button', { name: 'Save contact' });
        fireEvent.change(
            within(button.closest('form')!).getByLabelText('Role or title'),
            { target: { value: 'Revised role' } },
        );
        fireEvent.click(button);
        await waitFor(() => expect(refreshPageProps).toHaveBeenCalled());
        expect(apiRequest).toHaveBeenLastCalledWith(
            'PATCH',
            '/synthetic/contact-edit',
            expect.objectContaining({
                title: 'Revised role',
                expected_version: 'contact-version',
            }),
            expect.any(String),
        );
        const body = vi.mocked(apiRequest).mock.calls[0][2];
        expect(body).not.toHaveProperty('is_archived');
        expect(body).not.toHaveProperty('lock_version');
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('retains input and the logical attempt key after an uncertain network response', async () => {
        vi.mocked(apiRequest).mockResolvedValueOnce({
            ok: false,
            status: 0,
            message: 'Synthetic connection failure',
        });
        render(
            <CompanyProfile
                action="/synthetic/profile"
                profile={profile}
                version="same-version"
            />,
        );
        fireEvent.change(screen.getByLabelText('Internal company notes'), {
            target: { value: 'Keep this edit' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save company profile' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Synthetic connection failure',
        );
        expect(screen.getByLabelText('Internal company notes')).toHaveValue(
            'Keep this edit',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save company profile' }),
        );
        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
        expect(vi.mocked(apiRequest).mock.calls[0][3]).toBe(
            vi.mocked(apiRequest).mock.calls[1][3],
        );
    });

    it('confirms contact archival and keeps a refusal visible inside the confirmation', async () => {
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 409,
            message: 'This contact changed. Reload before archiving.',
        });
        const { container } = render(
            <ClientContacts
                createAction="/synthetic/new"
                contacts={[{ ...contact, is_archived: false }]}
            />,
        );
        fireEvent.click(container.querySelector('summary')!);
        fireEvent.click(
            screen.getByRole('button', { name: 'Archive contact' }),
        );
        expect(apiRequest).not.toHaveBeenCalled();
        const dialog = await screen.findByRole('alertdialog');
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Archive contact' }),
        );
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'This contact changed',
        );
        expect(apiRequest).toHaveBeenCalledWith(
            'POST',
            '/synthetic/contact-archive',
            { expected_version: 'contact-version', confirm: true },
            expect.any(String),
        );
        expect(refreshPageProps).not.toHaveBeenCalled();
    });
});
