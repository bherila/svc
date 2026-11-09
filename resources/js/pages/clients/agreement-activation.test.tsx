import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
import ClientAgreementDetail from '@/pages/clients/agreement';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation(),
        }),
    }),
}));
vi.mock('@/lib/api', () => ({ apiRequest: vi.fn() }));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn().mockResolvedValue(undefined),
}));

const ACTIVATE = {
    href: '/api/v1/workspaces/workspace-1/enrollments/enrollment-1/activate',
    version: 'v-opaque-1',
};

function agreementProps(activate: typeof ACTIVATE | null) {
    return {
        audience: 'operator' as const,
        actions: { update: null, activate_enrollment: activate },
        company: { id: 'company-1', name: 'Synthetic Client' },
        home_href: '/workspaces/workspace-1/clients/company-1',
        files: [],
        attachment_upload: null,
        agreement: {
            id: 'agreement-1',
            title: 'Synthetic Care plan',
            status: 'draft',
            is_recurring: true,
            currency: 'USD',
            version: 'v-agreement',
        },
        recurring_items: [],
        deferred_backlog: null,
    } as unknown as Parameters<typeof ClientAgreementDetail>[0];
}

describe('operator agreement: activate a signed service', () => {
    beforeEach(() => {
        vi.mocked(apiRequest).mockReset();
        vi.mocked(refreshPageProps).mockClear();
    });

    it('[ACC-5] shows no control when the server sent no activation', () => {
        render(<ClientAgreementDetail {...agreementProps(null)} />);

        expect(
            screen.queryByRole('button', { name: 'Activate service…' }),
        ).not.toBeInTheDocument();
    });

    it('[ACC-5] sends the confirmation and the version the page showed, then refreshes', async () => {
        vi.mocked(apiRequest).mockResolvedValue({
            ok: true,
            status: 200,
            data: {},
        });
        render(<ClientAgreementDetail {...agreementProps(ACTIVATE)} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Activate service…' }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Activate service' }),
        );

        expect(apiRequest).toHaveBeenCalledWith('POST', ACTIVATE.href, {
            expected_version: 'v-opaque-1',
            confirm: true,
        });
        expect(refreshPageProps).toHaveBeenCalled();
    });

    it('[ACC-5] keeps the dialog open and reads the refusal out', async () => {
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 422,
            message: 'Another agreement overlaps this one.',
        });
        render(<ClientAgreementDetail {...agreementProps(ACTIVATE)} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Activate service…' }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Activate service' }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Another agreement overlaps this one.',
        );
    });

    it('refreshes and says the record changed on a 409', async () => {
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 409,
            message: 'stale',
        });
        render(<ClientAgreementDetail {...agreementProps(ACTIVATE)} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Activate service…' }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Activate service' }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            /changed since the page loaded/,
        );
        expect(refreshPageProps).toHaveBeenCalled();
    });
});
