import { router } from '@inertiajs/react';
import { render, screen, within } from '@testing-library/react';
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
import type { PortalCheckout } from '@/types/packages';

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

function autopay(): NonNullable<PortalCheckout['autopay']> {
    return {
        limit_amount: 50000,
        currency: 'USD',
        limit_label: '$500.00',
        satisfied: false,
        unavailable: null,
        wording: null,
        enable_url: null,
        enable_fields: null,
        billing_url: '/portal/company-1/billing',
    };
}

function checkout(overrides: Partial<PortalCheckout> = {}): PortalCheckout {
    return {
        due_now: {
            currency: 'USD',
            lines: [
                {
                    kind: 'setup_fee',
                    description: 'Setup fee',
                    amount: 10000,
                    period_start: null,
                    period_end: null,
                },
                {
                    kind: 'first_period',
                    description: 'First period',
                    amount: 25000,
                    period_start: '2026-11-09',
                    period_end: '2026-11-30',
                },
            ],
            total: 35000,
            estimate: true,
        },
        pay_before_activation: true,
        pay_url: null,
        payment_options: null,
        card_setup: null,
        autopay: null,
        ...overrides,
    };
}

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

    it('[ACC-3] labels Due now an estimate before acceptance, with its lines and total', () => {
        render(
            <PortalService {...portalServiceProps({ checkout: checkout() })} />,
        );

        const card = screen.getByLabelText('Due now');

        expect(within(card).getByText('Due now (estimate)')).toBeVisible();
        expect(within(card).getByText('Setup fee')).toBeVisible();
        expect(within(card).getByText('$100.00')).toBeVisible();
        expect(within(card).getByText('$350.00')).toBeVisible();
        expect(within(card).getByText(/This is an estimate/)).toBeVisible();
        expect(
            within(card).getByText(/This offer is open until/),
        ).toBeVisible();
    });

    it('[ACC-3] offers the server’s pay link and card form after acceptance, and nothing when the URLs are null', () => {
        const accepted = {
            acceptance: {
                accepted_at: '2026-11-09T10:00:00Z',
                signer_name: 'Morgan Synthetic',
                signer_title: null,
            },
        };
        const { unmount } = render(
            <PortalService
                {...portalServiceProps({
                    service: portalService({
                        status: 'awaiting_payment',
                        ...accepted,
                        payment: {
                            ...portalService().payment,
                            state: 'due',
                            waiting_for: ['payment'],
                        },
                    }),
                    accept_url: null,
                    checkout: checkout({
                        due_now: { ...checkout().due_now, estimate: false },
                        pay_url: '/portal/company-1/invoices/invoice-1',
                        payment_options: {
                            intent_href:
                                '/portal/company-1/invoices/invoice-1/intent',
                            publishable_key: 'pk_test_fixture',
                        },
                    }),
                })}
            />,
        );

        expect(screen.getByText('Due now')).toBeVisible();
        expect(
            screen.queryByText(/This is an estimate/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open the invoice to pay' }),
        ).toHaveAttribute('href', '/portal/company-1/invoices/invoice-1');
        expect(
            screen.getByRole('button', { name: /pay/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/starts once the sign-up payment is done/),
        ).toBeVisible();

        unmount();
        render(
            <PortalService
                {...portalServiceProps({
                    service: portalService({
                        status: 'awaiting_payment',
                        ...accepted,
                    }),
                    accept_url: null,
                    checkout: checkout({
                        due_now: { ...checkout().due_now, estimate: false },
                    }),
                })}
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'Open the invoice to pay' }),
        ).not.toBeInTheDocument();
    });

    it('[ACC-4] posts the server’s consent fields, exactly, only after the box is ticked', async () => {
        const wording =
            'I authorize Example Studio to charge Visa ending 4242 up to $500.00 per invoice.';
        render(
            <PortalService
                {...portalServiceProps({
                    checkout: checkout({
                        autopay: {
                            ...autopay(),
                            wording,
                            enable_url: '/portal/company-1/autopay',
                            enable_fields: {
                                limit_amount: 50000,
                                wording,
                                service: 'enrollment-1',
                            },
                        },
                    }),
                })}
            />,
        );

        expect(screen.getByText(wording)).toBeVisible();
        expect(
            screen.getByText(/up to \$500.00 per invoice, for/),
        ).toBeVisible();

        const button = screen.getByRole('button', { name: 'Turn on autopay' });

        expect(button).toBeDisabled();

        await userEvent.click(
            screen.getByRole('checkbox', { name: /I authorize this/ }),
        );
        await userEvent.click(button);

        expect(router.post).toHaveBeenCalledWith(
            '/portal/company-1/autopay',
            {
                limit_amount: 50000,
                wording,
                service: 'enrollment-1',
                authorized: true,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('[ACC-4] offers to save a card first, and nothing to act on without the URLs', () => {
        const { unmount } = render(
            <PortalService
                {...portalServiceProps({
                    checkout: checkout({
                        card_setup: {
                            setup_url:
                                '/portal/company-1/payment-methods/setup',
                            confirm_url:
                                '/portal/company-1/payment-methods/confirm',
                            publishable_key: 'pk_test_fixture',
                        },
                        autopay: autopay(),
                    }),
                })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Save a card' }),
        ).toBeVisible();

        unmount();
        render(
            <PortalService
                {...portalServiceProps({
                    checkout: checkout({ autopay: autopay() }),
                })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Save a card' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Turn on autopay' }),
        ).not.toBeInTheDocument();
    });

    it('[ACC-4] says autopay is on once it meets the version', () => {
        render(
            <PortalService
                {...portalServiceProps({
                    checkout: checkout({
                        autopay: { ...autopay(), satisfied: true },
                    }),
                })}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'Autopay is on, up to $500.00 per invoice.',
        );
    });

    it('[ACC-4] says why autopay cannot meet the version, and offers nothing to act on', () => {
        const reason =
            'This service is paid by autopay in its own currency, and autopay for your account is in a different currency, so it cannot be set up here. Please contact us to sign up.';
        render(
            <PortalService
                {...portalServiceProps({
                    checkout: checkout({
                        card_setup: {
                            setup_url:
                                '/portal/company-1/payment-methods/setup',
                            confirm_url:
                                '/portal/company-1/payment-methods/confirm',
                            publishable_key: 'pk_test_fixture',
                        },
                        autopay: { ...autopay(), unavailable: reason },
                    }),
                })}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(reason);
        expect(
            screen.queryByRole('button', { name: 'Save a card' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Turn on autopay' }),
        ).not.toBeInTheDocument();
    });

    it('[ACC-6] has no layout risk with oversized line descriptions and wording', () => {
        const { container } = render(
            <PortalService
                {...portalServiceProps({
                    checkout: checkout({
                        due_now: {
                            ...checkout().due_now,
                            lines: [
                                {
                                    kind: 'setup_fee',
                                    description: LONG.repeat(5),
                                    amount: 10000,
                                    period_start: null,
                                    period_end: null,
                                },
                            ],
                        },
                        autopay: {
                            ...autopay(),
                            wording: LONG.repeat(10),
                            enable_url: '/portal/company-1/autopay',
                            enable_fields: {
                                limit_amount: 50000,
                                wording: LONG.repeat(10),
                                service: 'enrollment-1',
                            },
                        },
                    }),
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
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
