import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
import Settings from './settings';

vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(),
    newIdempotencyKey: () => crypto.randomUUID(),
}));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn(async () => {}),
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        Link: ({ children }: { children: ReactNode }) => <>{children}</>,
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (field: string, value: unknown) =>
                    setData((prior) => ({ ...prior, [field]: value })),
                reset: () => setData(initial),
            };
        },
    };
});

const company = {
    id: 'synthetic-company',
    version: 'version-one',
    update_url: '/synthetic/company-update',
    name: 'Original company',
    billing_email: 'billing@example.test',
    is_active: true,
};
const profile = {
    billing_address: null,
    phone: null,
    website_url: null,
    tax_id: null,
    currency: null,
    notes: 'Original notes',
};
const props = {
    workspace: { id: 'synthetic-workspace' },
    company,
    company_profile: profile,
    profile_action: '/synthetic/profile-update',
    projects: [],
    assignable: [],
    portal: { add_url: '/synthetic/portal', members: [] },
};

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(apiRequest).mockResolvedValue({
        ok: true,
        status: 200,
        data: { version: 'version-two' },
    });
});

describe('shared company form versions', () => {
    it('keeps the profile draft when the client form saves and rebases unchanged profile facts', async () => {
        const view = render(<Settings {...props} />);
        fireEvent.change(screen.getByLabelText('Name', { exact: true }), {
            target: { value: 'Saved client name' },
        });
        fireEvent.change(screen.getByLabelText('Internal company notes'), {
            target: { value: 'Unsaved profile draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save client' }));
        await waitFor(() => expect(refreshPageProps).toHaveBeenCalled());
        view.rerender(
            <Settings
                {...props}
                company={{
                    ...company,
                    name: 'Saved client name',
                    version: 'version-two',
                }}
            />,
        );
        expect(screen.getByLabelText('Internal company notes')).toHaveValue(
            'Unsaved profile draft',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save company profile' }),
        );
        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
        expect(apiRequest).toHaveBeenLastCalledWith(
            'PATCH',
            '/synthetic/profile-update',
            expect.objectContaining({
                notes: 'Unsaved profile draft',
                expected_version: 'version-two',
            }),
            expect.any(String),
        );
    });

    it('keeps the client draft when the profile form saves and rebases unchanged client facts', async () => {
        const view = render(<Settings {...props} />);
        fireEvent.change(screen.getByLabelText('Name', { exact: true }), {
            target: { value: 'Unsaved client draft' },
        });
        fireEvent.change(screen.getByLabelText('Internal company notes'), {
            target: { value: 'Saved profile notes' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save company profile' }),
        );
        await waitFor(() => expect(refreshPageProps).toHaveBeenCalled());
        view.rerender(
            <Settings
                {...props}
                company={{ ...company, version: 'version-two' }}
                company_profile={{ ...profile, notes: 'Saved profile notes' }}
            />,
        );
        expect(screen.getByLabelText('Name', { exact: true })).toHaveValue(
            'Unsaved client draft',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save client' }));
        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
        expect(apiRequest).toHaveBeenLastCalledWith(
            'PATCH',
            '/synthetic/company-update',
            expect.objectContaining({
                name: 'Unsaved client draft',
                expected_version: 'version-two',
            }),
            expect.any(String),
        );
    });

    it('keeps a conflicting draft on its original version when its own baseline changed', async () => {
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 409,
            message: 'This company changed. Reload before saving.',
        });
        const view = render(<Settings {...props} />);
        fireEvent.change(screen.getByLabelText('Internal company notes'), {
            target: { value: 'Keep my unsaved notes' },
        });
        view.rerender(
            <Settings
                {...props}
                company={{ ...company, version: 'version-two' }}
                company_profile={{
                    ...profile,
                    notes: 'A concurrent editor changed the notes',
                }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save company profile' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This company changed',
        );
        expect(screen.getByLabelText('Internal company notes')).toHaveValue(
            'Keep my unsaved notes',
        );
        expect(apiRequest).toHaveBeenCalledWith(
            'PATCH',
            '/synthetic/profile-update',
            expect.objectContaining({
                notes: 'Keep my unsaved notes',
                expected_version: 'version-one',
            }),
            expect.any(String),
        );
    });
});
