import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import PublicPackageCatalog from '@/pages/packages/public/catalog';
import PublicPackageShow from '@/pages/packages/public/show';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import type {
    PublicCatalogProps,
    PublicPackageDetails,
    PublicPackageProps,
} from '@/types/packages';

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
}));

const LONG = 'Unbroken'.repeat(14);
const ORIGIN = window.location.origin;

function details(
    overrides: Partial<PublicPackageDetails> = {},
): PublicPackageDetails {
    return {
        name: 'Hosting + Care',
        summary: 'Hosting plus a block of hours.',
        currency: 'USD',
        billing_cadence: 'monthly',
        cycle_amount: 64500,
        setup_fee_amount: 25000,
        minimum_term_months: 6,
        url: `${ORIGIN}/s/tok123/hosting-care`,
        body_markdown:
            '- Managed hosting\n- 5 hours a month\n\nExtra hours are billed monthly.',
        components: [
            {
                description: 'Managed hosting',
                kind: 'recurring',
                cadence: 'monthly',
                amount: 4500,
            },
            {
                description: 'Setup and migration',
                kind: 'setup_fee',
                cadence: 'one_time',
                amount: 25000,
            },
        ],
        hourly_rate_amount: 14000,
        retainer_amount: 60000,
        retainer_minutes: 300,
        rollover_months: 2,
        signup: {
            setup_fee_amount: 25000,
            charges_first_period: true,
            requires_autopay: true,
            autopay_limit_amount: 100000,
        },
        notice_days: 30,
        cancellation_mode: 'request',
        ...overrides,
    };
}

function catalogProps(
    overrides: Partial<PublicCatalogProps> = {},
): PublicCatalogProps {
    const {
        name,
        summary,
        currency,
        billing_cadence,
        cycle_amount,
        setup_fee_amount,
        minimum_term_months,
        url,
    } = details();

    return {
        workspace: { name: 'Synthetic Provider' },
        packages: [
            {
                name,
                summary,
                currency,
                billing_cadence,
                cycle_amount,
                setup_fee_amount,
                minimum_term_months,
                url,
            },
        ],
        sign_in_url: '/login',
        ...overrides,
    };
}

function showProps(
    overrides: Partial<PublicPackageProps> = {},
): PublicPackageProps {
    return {
        workspace: { name: 'Synthetic Provider' },
        package: details(),
        catalog_url: `${ORIGIN}/s/tok123`,
        sign_in_url: '/login',
        request: null,
        ...overrides,
    };
}

describe('public catalog', () => {
    it('shows each offer with its price, and no id', () => {
        const { container } = render(
            <PublicPackageCatalog {...catalogProps()} />,
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Services from Synthetic Provider',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('$645.00 per month')).toBeInTheDocument();
        expect(
            screen.getByText('Setup $250.00 · 6-month minimum'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'See details: Hosting + Care' }),
        ).toHaveAttribute('href', `${ORIGIN}/s/tok123/hosting-care`);
        expect(
            screen.getAllByRole('link', { name: 'Sign in' })[0],
        ).toHaveAttribute('href', '/login');
        expect(container.innerHTML).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-/);
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('says when nothing is listed', () => {
        render(<PublicPackageCatalog {...catalogProps({ packages: [] })} />);

        expect(
            screen.getByText('Nothing is listed here right now.'),
        ).toBeInTheDocument();
    });

    it('[PUB-7] references nothing outside this origin', () => {
        const { container } = render(
            <PublicPackageCatalog {...catalogProps()} />,
        );

        expectNoExternalReferences(container);
    });

    it('survives hostile names', () => {
        const { container } = render(
            <PublicPackageCatalog
                {...catalogProps({
                    workspace: { name: LONG },
                    packages: [
                        {
                            ...catalogProps().packages[0],
                            name: LONG,
                            summary: LONG.repeat(3),
                        },
                    ],
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

describe('public package page', () => {
    it('shows the offer in words and no form', () => {
        const { container } = render(<PublicPackageShow {...showProps()} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Hosting + Care' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Managed hosting', { selector: 'li' }),
        ).toBeInTheDocument();
        expect(screen.getByText('$45.00 per month')).toBeInTheDocument();
        expect(screen.getByText('$250.00 once')).toBeInTheDocument();
        expect(screen.getByText('$140.00 per hour')).toBeInTheDocument();
        expect(screen.getByText(/Retainer · 5 h included/)).toBeInTheDocument();
        expect(screen.getByText(/Roll over for 2 months/)).toBeInTheDocument();
        expect(
            screen.getByText(
                /\$250\.00 setup fee by card, the first period in advance, a saved card for autopay, up to \$1,000\.00 per invoice/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('6-month minimum, then 30 days’ notice'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'All services' }),
        ).toHaveAttribute('href', `${ORIGIN}/s/tok123`);
        expect(
            screen.getAllByRole('link', { name: 'Sign in' })[0],
        ).toHaveAttribute('href', '/login');
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(container.innerHTML).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-/);
        expect(screen.queryByText('one_time')).not.toBeInTheDocument();
    });

    it('says nothing is charged when nothing is', () => {
        render(
            <PublicPackageShow
                {...showProps({
                    package: details({
                        signup: {
                            setup_fee_amount: null,
                            charges_first_period: false,
                            requires_autopay: false,
                            autopay_limit_amount: null,
                        },
                    }),
                })}
            />,
        );

        expect(
            screen.getByText('Nothing is charged at sign-up.'),
        ).toBeInTheDocument();
    });

    it('renders operator copy as text, never as markup', () => {
        const { container } = render(
            <PublicPackageShow
                {...showProps({
                    package: details({
                        body_markdown:
                            '<img src="https://tracker.example.test/x.png"> <script>alert(1)</script>',
                    }),
                })}
            />,
        );

        expect(container.querySelector('script, img')).toBeNull();
        expectNoExternalReferences(container);
    });

    it('[PUB-7] references nothing outside this origin', () => {
        const { container } = render(<PublicPackageShow {...showProps()} />);

        expectNoExternalReferences(container);
    });

    it('survives hostile names', () => {
        const { container } = render(
            <PublicPackageShow
                {...showProps({
                    workspace: { name: LONG },
                    package: details({
                        name: LONG,
                        summary: LONG,
                        body_markdown: `- ${LONG}\n\n${LONG}`,
                        components: [
                            {
                                description: LONG,
                                kind: 'recurring',
                                cadence: 'monthly',
                                amount: 100,
                            },
                        ],
                    }),
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

/** No script, stylesheet, font, image or frame, and no link or source on another host. */
function expectNoExternalReferences(container: HTMLElement) {
    expect(
        container.querySelectorAll(
            'script, link, img, iframe, video, audio, object, embed, source',
        ),
    ).toHaveLength(0);

    for (const element of container.querySelectorAll(
        '[src], [href], [srcset], [action], [poster]',
    )) {
        for (const attribute of ['src', 'href', 'srcset', 'action', 'poster']) {
            const value = element.getAttribute(attribute);

            if (value !== null) {
                expect(new URL(value, window.location.href).origin).toBe(
                    ORIGIN,
                );
            }
        }
    }

    expect(container.innerHTML).not.toMatch(/url\(\s*['"]?https?:/);
}
