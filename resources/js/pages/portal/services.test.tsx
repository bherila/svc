import { router } from '@inertiajs/react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PortalServices from '@/pages/portal/services';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { portalService, portalServicesProps } from '@/test/package-fixtures';
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

describe('portal services', () => {
    beforeEach(() => vi.mocked(router.post).mockReset());

    it('lists services with the server’s own URL, one status badge per row', () => {
        render(
            <PortalServices
                {...portalServicesProps({
                    services: [
                        portalService({ status: 'awaiting_review' }),
                        portalService({
                            id: 'enrollment-2',
                            name: 'Site audit',
                            status: 'active',
                            activated_at: '2026-11-09T10:00:00Z',
                            url: '/portal/company-1/services/enrollment-2',
                        }),
                    ],
                })}
            />,
        );

        const rows = screen.getAllByRole('row').slice(1);

        expect(rows).toHaveLength(2);
        expect(
            within(rows[0]).getByRole('link', { name: 'Synthetic Care plan' }),
        ).toHaveAttribute('href', '/portal/company-1/services/enrollment-1');
        expect(within(rows[0]).getByText('Awaiting review')).toBeVisible();
        expect(within(rows[1]).getByText('Active')).toBeVisible();
        expect(within(rows[1]).getByText(/\$645\.00 per month/)).toBeVisible();
    });

    it('[ACC-8] posts the package to the server’s add URL', async () => {
        render(<PortalServices {...portalServicesProps()} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Review and add…' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/portal/company-1/services',
            { package: 'package-1' },
            expect.any(Object),
        );
    });

    it('[ACC-8] shows a refusal from the server and does not retry it blindly', async () => {
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onError?.({ package: 'That package cannot be added.' });
            options?.onFinish?.({} as never);
        });
        render(<PortalServices {...portalServicesProps()} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Review and add…' }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'That package cannot be added.',
        );
    });

    it('[ACC-8] draws no add section for a contact who may not add', () => {
        render(
            <PortalServices
                {...portalServicesProps({ addable_packages: null })}
            />,
        );

        expect(
            screen.queryByRole('heading', { name: 'Add a service' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Your services' }),
        ).toBeVisible();
    });

    it('explains a package that cannot be added instead of offering the button', () => {
        const props = portalServicesProps();
        const [pkg] = props.addable_packages ?? [];

        render(
            <PortalServices
                {...props}
                addable_packages={[
                    {
                        ...pkg,
                        add_url: null,
                        add_refusal: {
                            code: 'package_payment_not_available_yet',
                            message: 'Online payment is not available yet.',
                        },
                    },
                ]}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Review and add…' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Online payment is not available yet.'),
        ).toBeVisible();
    });

    it('has no layout risk with oversized names', () => {
        const props = portalServicesProps();
        const { container } = render(
            <PortalServices
                {...props}
                services={[portalService({ name: LONG })]}
                addable_packages={(props.addable_packages ?? []).map((pkg) => ({
                    ...pkg,
                    name: LONG,
                    summary: LONG,
                }))}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
