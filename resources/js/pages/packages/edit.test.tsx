import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PackageEdit from '@/pages/packages/edit';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import {
    editProps,
    packageTerms,
    packageVersion,
    servicePackage,
} from '@/test/package-fixtures';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

const api = vi.hoisted(() => ({ apiRequest: vi.fn() }));
const refresh = vi.hoisted(() => ({ refreshPageProps: vi.fn() }));
const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    apiRequest: api.apiRequest,
}));
vi.mock('@/lib/refresh-page-props', () => refresh);
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
    router: { visit: inertia.visit },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        }),
    }),
}));

const LONG = 'Unbroken'.repeat(14);

describe('package editor', () => {
    beforeEach(() => {
        api.apiRequest.mockReset();
        refresh.refreshPageProps.mockReset().mockResolvedValue(undefined);
        inertia.visit.mockReset();
    });

    it('edits the draft terms, with money and hours as people write them', () => {
        render(
            <PackageEdit
                {...editProps({
                    package: servicePackage({
                        draft: packageVersion(
                            2,
                            packageTerms({
                                retainer_amount: 65000,
                                retainer_minutes: 300,
                            }),
                        ),
                    }),
                })}
            />,
        );

        expect(screen.getByLabelText('Name')).toHaveValue('Synthetic Hosting');
        expect(screen.getByLabelText('Component 1 amount')).toHaveValue(
            '45.00',
        );
        expect(screen.getByLabelText('Retainer amount')).toHaveValue('650.00');
        expect(screen.getByLabelText('Retainer hours')).toHaveValue('5 h');
        expect(screen.getByText(/editing v2 \(draft\)/)).toBeInTheDocument();
        // A select shows its label, never the stored value.
        expect(screen.getByLabelText('Billing cadence')).toHaveTextContent(
            'Monthly',
        );
        expect(screen.queryByText('semi_annual')).not.toBeInTheDocument();
    });

    it('falls back to the published terms when there is no draft', () => {
        render(
            <PackageEdit
                {...editProps({
                    package: servicePackage({
                        status: 'published',
                        draft: null,
                        published: packageVersion(
                            1,
                            packageTerms({ name: 'Live Name' }),
                            true,
                        ),
                    }),
                })}
            />,
        );

        expect(screen.getByLabelText('Name')).toHaveValue('Live Name');
        expect(screen.getByText(/starts a new draft/)).toBeInTheDocument();
    });

    it('converts decimal currency to minor units and hours to minutes on save', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: {} });
        render(<PackageEdit {...editProps()} />);

        const amount = screen.getByLabelText('Component 1 amount');
        await user.clear(amount);
        await user.type(amount, '49.99');
        await user.type(screen.getByLabelText('Retainer hours'), '2h 30m');
        await user.type(screen.getByLabelText('Retainer amount'), '600');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(api.apiRequest).toHaveBeenCalledTimes(1));
        const [method, url, body] = api.apiRequest.mock.calls[0] as [
            string,
            string,
            Record<string, unknown>,
        ];

        expect(method).toBe('PATCH');
        expect(url).toBe('/api/v1/workspaces/ws-1/packages/package-1');
        expect(body.expected_version).toBe('a'.repeat(64));
        expect(body.retainer_amount).toBe(60000);
        expect(body.retainer_minutes).toBe(150);
        expect(body.link_name).toBe('synthetic-hosting');
        expect(body.components).toEqual([
            {
                kind: 'recurring',
                description: 'Managed hosting',
                cadence: 'monthly',
                amount: 4999,
                quantity: 1,
                is_taxable: false,
            },
        ]);
        // Existing keys survive an edit.
        expect(body.intake_questions).toEqual([
            {
                key: 'domain',
                label: 'Website address',
                kind: 'short_text',
                required: true,
            },
        ]);
        await waitFor(() =>
            expect(refresh.refreshPageProps).toHaveBeenCalled(),
        );
    });

    it('sends nothing while an amount is not a number', async () => {
        const user = userEvent.setup();
        render(<PackageEdit {...editProps()} />);

        const amount = screen.getByLabelText('Component 1 amount');
        await user.clear(amount);
        await user.type(amount, 'abc');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(api.apiRequest).not.toHaveBeenCalled();
        expect(
            await screen.findByText('Enter an amount such as 45.00.'),
        ).toBeInTheDocument();
    });

    it('creates a package at the store url and goes to the list', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 201, data: {} });
        render(
            <PackageEdit
                {...editProps({
                    package: null,
                    defaults: {
                        currency: 'EUR',
                        billing_cadence: 'monthly',
                        notice_days: 30,
                        payment_lapse_days: 14,
                        activation_mode: 'review',
                        invoice_issuing: 'draft',
                        cancellation_mode: 'request',
                        components: [],
                        intake_questions: [],
                    },
                    urls: {
                        index: '/workspaces/workspace-1/packages',
                        store: '/api/v1/workspaces/ws-1/packages',
                        update: null,
                        publish: null,
                        retire: null,
                    },
                })}
            />,
        );

        await user.type(screen.getByLabelText('Name'), 'Fresh');
        await user.type(screen.getByLabelText('Link name'), 'fresh');
        await user.click(screen.getByRole('button', { name: 'Create draft' }));

        await waitFor(() => expect(api.apiRequest).toHaveBeenCalled());
        const [method, url, body] = api.apiRequest.mock.calls[0] as [
            string,
            string,
            Record<string, unknown>,
        ];
        expect(method).toBe('POST');
        expect(url).toBe('/api/v1/workspaces/ws-1/packages');
        expect(body).toMatchObject({
            name: 'Fresh',
            link_name: 'fresh',
            currency: 'EUR',
        });
        expect(body).not.toHaveProperty('expected_version');
        await waitFor(() =>
            expect(inertia.visit).toHaveBeenCalledWith(
                '/workspaces/workspace-1/packages',
            ),
        );
        expect(
            screen.queryByRole('button', { name: /Publish/ }),
        ).not.toBeInTheDocument();
    });

    it('shows the server’s field errors on a 422', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'The given data was invalid.',
            errors: {
                link_name: ['Another package already uses that link name.'],
            },
        });
        render(<PackageEdit {...editProps()} />);

        await user.type(screen.getByLabelText('Short description'), ' more');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(
            await screen.findByText(
                'Another package already uses that link name.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('The given data was invalid.'),
        ).toBeInTheDocument();
        expect(refresh.refreshPageProps).not.toHaveBeenCalled();
    });

    it('tells the user the package changed on a 409 and reloads on request', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: false,
            status: 409,
            message: 'Version mismatch.',
        });
        render(<PackageEdit {...editProps()} />);

        await user.type(screen.getByLabelText('Short description'), ' more');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(
            await screen.findByText(/This package changed since you opened it/),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Reload' }));
        expect(refresh.refreshPageProps).toHaveBeenCalled();
    });

    it('disables Publish and says why while the server lists refusals', () => {
        render(
            <PackageEdit
                {...editProps({
                    package: servicePackage({
                        publish_refusals: [
                            {
                                code: 'package_payment_needs_stripe',
                                message:
                                    'The workspace needs a Stripe connection before it can be published.',
                            },
                        ],
                    }),
                })}
            />,
        );

        expect(screen.getByRole('button', { name: /Publish/ })).toBeDisabled();
        const why = screen.getByRole('group', {
            name: 'Why this cannot be published yet',
        });
        expect(
            within(why).getByText(/needs a Stripe connection/),
        ).toBeInTheDocument();
    });

    it('publishes only after confirming, with confirm and expected_version', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: {} });
        render(<PackageEdit {...editProps()} />);

        await user.click(screen.getByRole('button', { name: /Publish/ }));
        expect(api.apiRequest).not.toHaveBeenCalled();

        const dialog = await screen.findByRole('alertdialog');
        await user.click(
            within(dialog).getByRole('button', { name: 'Publish' }),
        );

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/api/v1/workspaces/ws-1/packages/package-1/publish',
                { expected_version: 'a'.repeat(64), confirm: true },
            ),
        );
        await waitFor(() =>
            expect(refresh.refreshPageProps).toHaveBeenCalled(),
        );
    });

    it('keeps Publish off until unsaved edits are saved', async () => {
        const user = userEvent.setup();
        render(<PackageEdit {...editProps()} />);

        expect(screen.getByRole('button', { name: /Publish/ })).toBeEnabled();
        await user.type(screen.getByLabelText('Short description'), ' more');
        expect(screen.getByRole('button', { name: /Publish/ })).toBeDisabled();
        expect(
            screen.getByText('Save your changes before publishing.'),
        ).toBeInTheDocument();
    });

    it('retires only after confirming, with confirm and expected_version', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: {} });
        render(
            <PackageEdit
                {...editProps({
                    package: servicePackage({
                        status: 'published',
                        draft: null,
                        published: packageVersion(1, packageTerms(), true),
                        public_url:
                            'https://svc.example.test/s/tok/synthetic-hosting',
                    }),
                    urls: {
                        index: '/workspaces/workspace-1/packages',
                        store: null,
                        update: '/api/v1/workspaces/ws-1/packages/package-1',
                        publish: null,
                        retire: '/api/v1/workspaces/ws-1/packages/package-1/retire',
                    },
                })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Publish/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Preview page' }),
        ).toHaveAttribute(
            'href',
            'https://svc.example.test/s/tok/synthetic-hosting',
        );
        await user.click(screen.getByRole('button', { name: /Retire/ }));
        const dialog = await screen.findByRole('alertdialog');
        await user.click(
            within(dialog).getByRole('button', { name: 'Retire' }),
        );

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/api/v1/workspaces/ws-1/packages/package-1/retire',
                { expected_version: 'a'.repeat(64), confirm: true },
            ),
        );
    });

    it('adds and removes components and questions', async () => {
        const user = userEvent.setup();
        render(<PackageEdit {...editProps()} />);

        await user.click(screen.getByRole('button', { name: 'Add component' }));
        expect(
            screen.getByLabelText('Component 2 description'),
        ).toBeInTheDocument();
        await user.click(
            screen.getByRole('button', { name: 'Remove component 2' }),
        );
        expect(
            screen.queryByLabelText('Component 2 description'),
        ).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Add question' }));
        expect(screen.getByLabelText('Question 2 text')).toBeInTheDocument();
    });

    it('[X-4] draws inside the workspace shell and survives hostile text', () => {
        const hostile = editProps({
            package: servicePackage({
                link_name: LONG.toLowerCase(),
                draft: packageVersion(
                    1,
                    packageTerms({
                        name: LONG,
                        summary: LONG,
                        components: [
                            {
                                kind: 'recurring',
                                description: LONG.repeat(3),
                                cadence: 'monthly',
                                amount: 100,
                                quantity: '1.000',
                                is_taxable: false,
                            },
                        ],
                    }),
                ),
                publish_refusals: [{ code: 'x', message: LONG.repeat(3) }],
            }),
        });
        const { container } = render(<PackageEdit {...hostile} />);

        const main = container.querySelector('main');
        expect(main?.className).toContain('max-w-6xl');
        expect(main?.className).not.toMatch(/max-w-(?!6xl)/);
        expect(main?.className).toContain('grid-cols-1');
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
