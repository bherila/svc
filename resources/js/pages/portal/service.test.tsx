import { router } from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PortalService from '@/pages/portal/service';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import {
    clientTerms,
    portalService,
    portalServiceProps,
} from '@/test/package-fixtures';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: vi.fn(), post: vi.fn() },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: 'company-1',
            }),
        }),
    }),
}));

const LONG = 'Unbroken'.repeat(14);
const ACCEPT = '/portal/company-1/services/enrollment-1/accept';

describe('portal service review and sign', () => {
    beforeEach(() => vi.mocked(router.post).mockReset());

    it('[ACC-1] needs a name and the acceptance box before anything is sent', async () => {
        render(<PortalService {...portalServiceProps()} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Accept and sign' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        expect(screen.getByText('Enter your full name.')).toBeVisible();
        expect(
            screen.getByText('Tick the box to accept the agreement.'),
        ).toBeVisible();
    });

    it('[ACC-1] posts the signer and acceptance exactly as the server validates them', async () => {
        render(<PortalService {...portalServiceProps()} />);

        await userEvent.type(
            screen.getByLabelText('Full name'),
            '  Morgan Synthetic ',
        );
        await userEvent.type(
            screen.getByLabelText('Title (optional)'),
            'Owner',
        );
        await userEvent.click(
            screen.getByRole('checkbox', {
                name: /accept it on behalf of Synthetic Client/,
            }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Accept and sign' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            ACCEPT,
            {
                signer_name: 'Morgan Synthetic',
                signer_title: 'Owner',
                accepted_terms: true,
            },
            expect.any(Object),
        );
    });

    it('leaves the optional title out when it is empty', async () => {
        render(<PortalService {...portalServiceProps()} />);

        await userEvent.type(screen.getByLabelText('Full name'), 'Morgan');
        await userEvent.click(screen.getByRole('checkbox'));
        await userEvent.click(
            screen.getByRole('button', { name: 'Accept and sign' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            ACCEPT,
            { signer_name: 'Morgan', accepted_terms: true },
            expect.any(Object),
        );
    });

    it('[ACC-1] reads a coded refusal out instead of losing it', async () => {
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onError?.({
                acceptance: 'This offer is no longer open.',
            });
            options?.onFinish?.({} as never);
        });
        render(<PortalService {...portalServiceProps()} />);

        await userEvent.type(screen.getByLabelText('Full name'), 'Morgan');
        await userEvent.click(screen.getByRole('checkbox'));
        await userEvent.click(
            screen.getByRole('button', { name: 'Accept and sign' }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This offer is no longer open.',
        );
    });

    it('[ACC-1] shows no form when the server sent no accept URL, and says why', () => {
        render(
            <PortalService
                {...portalServiceProps({
                    accept_url: null,
                    accept_refusal: {
                        code: 'package_payment_unavailable',
                        message: 'Online payment is not available yet.',
                    },
                })}
            />,
        );

        expect(screen.queryByLabelText('Full name')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Accept and sign' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Online payment is not available yet.'),
        ).toBeVisible();
    });

    it('[EDGE-7] says what changed since the offer and offers the first terms', () => {
        render(
            <PortalService
                {...portalServiceProps({
                    changed_terms: ['notice_days', 'components'],
                    offered_terms: clientTerms({ notice_days: 14 }),
                })}
            />,
        );

        expect(
            screen.getByText(
                'Updated since you were offered: Notice period, Prices',
            ),
        ).toBeVisible();
        expect(
            screen.getByText('The terms as first offered'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'What is shown below is what signing accepts now.',
            ),
        ).toBeVisible();
    });

    it('[EDGE-7] shows nothing about changes when none were made', () => {
        render(<PortalService {...portalServiceProps()} />);

        expect(
            screen.queryByText(/Updated since you were offered/),
        ).not.toBeInTheDocument();
    });

    it('shows the resulting state once signed: awaiting review', () => {
        render(
            <PortalService
                {...portalServiceProps({
                    accept_url: null,
                    service: portalService({
                        status: 'awaiting_review',
                        acceptance: {
                            accepted_at: '2026-11-09T10:00:00Z',
                            signer_name: 'Morgan Synthetic',
                            signer_title: 'Owner',
                        },
                    }),
                })}
            />,
        );

        expect(
            screen.getByText('Thanks, we have your signature'),
        ).toBeVisible();
        expect(
            screen.getByText(/Signed by Morgan Synthetic, Owner/),
        ).toBeVisible();
        expect(screen.getByText('Awaiting review')).toBeVisible();
        expect(screen.queryByLabelText('Full name')).not.toBeInTheDocument();
        expect(screen.getByText('Review by us').closest('li')).toHaveAttribute(
            'aria-current',
            'step',
        );
    });

    it('shows the resulting state once signed: active, with the agreement', () => {
        render(
            <PortalService
                {...portalServiceProps({
                    accept_url: null,
                    service: portalService({
                        status: 'active',
                        terms: clientTerms({ activation_mode: 'automatic' }),
                        agreement_url:
                            '/portal/company-1/agreements/agreement-1',
                        acceptance: {
                            accepted_at: '2026-11-09T10:00:00Z',
                            signer_name: 'Morgan Synthetic',
                            signer_title: null,
                        },
                    }),
                })}
            />,
        );

        expect(screen.getByText('Thanks, you’re all set')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'View the signed agreement' }),
        ).toHaveAttribute('href', '/portal/company-1/agreements/agreement-1');
        expect(screen.queryByText('Review by us')).not.toBeInTheDocument();
    });

    it('has no layout risk with oversized names and agreement text', () => {
        const { container } = render(
            <PortalService
                {...portalServiceProps({
                    service: portalService({
                        name: LONG,
                        terms: clientTerms({ agreement_text: LONG.repeat(20) }),
                    }),
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
