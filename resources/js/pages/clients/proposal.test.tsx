import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientProposalDetail from '@/pages/clients/proposal';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    useForm: () => ({
        data: { signer_name: '', signer_title: '' },
        errors: {
            engagement:
                'This proposal cannot be accepted automatically. Ask an operator to verify its agreement link.',
        },
        processing: false,
        post: vi.fn(),
        setData: vi.fn(),
    }),
}));

vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

describe('client proposal detail', () => {
    it('shows a form-level acceptance refusal returned by the server', () => {
        render(
            <ClientProposalDetail
                company={{ id: 'company-1', name: 'Synthetic Client' }}
                home_href="/portal/company-1"
                proposal={{
                    id: 'proposal-1',
                    title: 'Synthetic Proposal',
                    summary: null,
                    terms: null,
                    status: 'sent',
                    currency: 'USD',
                    valid_until: null,
                    sent_at: '2026-09-01T12:00:00Z',
                    accepted_at: null,
                    total_amount: 10000,
                }}
                items={[]}
                accept_href="/portal/company-1/proposals/proposal-1/accept"
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'This proposal cannot be accepted automatically. Ask an operator to verify its agreement link.',
        );
    });
});
