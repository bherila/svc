import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PackageMove from '@/pages/packages/move';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { moveCandidate, moveProps } from '@/test/package-fixtures';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

const api = vi.hoisted(() => ({ apiRequest: vi.fn() }));
const inertia = vi.hoisted(() => ({ visit: vi.fn(), get: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    apiRequest: api.apiRequest,
}));
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
    router: { visit: inertia.visit, get: inertia.get },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        }),
    }),
}));

const LONG = 'Unbroken'.repeat(14);

describe('moving package subscribers', () => {
    beforeEach(() => {
        api.apiRequest.mockReset();
        inertia.visit.mockReset();
        inertia.get.mockReset();
    });

    it('[MIG-1] shows what changes, the dates on offer and who can move on the chosen one', () => {
        render(
            <PackageMove
                {...moveProps(
                    {},
                    {
                        subscribers: [
                            moveCandidate(),
                            moveCandidate({
                                enrollment_id: 'enrollment-2',
                                client: {
                                    id: 'company-2',
                                    name: 'Pine Street Dental',
                                },
                                refusal: {
                                    code: 'package_migration_date_not_offered',
                                    message:
                                        'That effective date is not a billing period start after this subscriber’s notice.',
                                },
                            }),
                        ],
                    },
                )}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Move subscribers from v2 to v3',
            }),
        ).toBeInTheDocument();
        const changes = screen.getByLabelText('What changes');
        expect(
            within(changes).getByText('$645.00 a month'),
        ).toBeInTheDocument();
        expect(
            within(changes).getByText('$695.00 a month'),
        ).toBeInTheDocument();
        // The Select shows the chosen date's label, not the raw value.
        expect(screen.getByLabelText('Takes effect')).toHaveTextContent(
            'Jan 1, 2027 · suits 2 of 2',
        );
        expect(
            screen.getByRole('checkbox', {
                name: 'Move Lakeside Synthetic Bakery',
            }),
        ).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: 'Move Pine Street Dental' }),
        ).toBeDisabled();
        expect(
            screen.getByText(/not a billing period start/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Schedule for 1 client and send notices',
            }),
        ).toBeEnabled();
    });

    it('[MIG-1] asks the server again for another date', async () => {
        const user = userEvent.setup();
        render(<PackageMove {...moveProps()} />);

        await user.click(screen.getByLabelText('Takes effect'));
        await user.click(
            await screen.findByRole('option', {
                name: 'Feb 1, 2027 · suits 3 of 1',
            }),
        );

        expect(inertia.get).toHaveBeenCalledWith(
            '/workspaces/workspace-1/packages/package-1/move?from=version-2',
            { effective_on: '2027-02-01' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('[MIG-2] schedules the chosen subscribers with the message, once confirmed, at the server’s endpoint', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 201, data: {} });
        render(<PackageMove {...moveProps()} />);

        await user.type(
            screen.getByLabelText(
                'Message to clients (added to the notice email)',
            ),
            'Our costs went up.',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Schedule for 1 client and send notices',
            }),
        );
        const dialog = await screen.findByRole('alertdialog');
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Schedule and send notices',
            }),
        );

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/api/v1/workspaces/workspace-1/packages/package-1/migrations',
                {
                    expected_version: 'a'.repeat(64),
                    confirm: true,
                    from_version_id: 'version-2',
                    effective_on: '2027-01-01',
                    enrollment_ids: ['enrollment-1'],
                    message: 'Our costs went up.',
                },
            ),
        );
        expect(inertia.visit).toHaveBeenCalledWith(
            '/workspaces/workspace-1/packages/package-1',
        );
    });

    it('[MIG-4] flags an autopay limit below the new price and a pending cancellation', () => {
        render(
            <PackageMove
                {...moveProps(
                    {},
                    {
                        subscribers: [
                            moveCandidate({
                                autopay_limit_amount: 60000,
                                autopay_below_price: true,
                                pending_cancellation_end_on: '2027-01-31',
                            }),
                        ],
                    },
                )}
            />,
        );

        expect(screen.getByText('$600.00')).toBeInTheDocument();
        expect(screen.getByText('Limit below new price')).toBeInTheDocument();
        expect(screen.getByText('Cancellation pending')).toBeInTheDocument();
        expect(
            screen.getByText(/SVC never raises a client.s autopay limit/),
        ).toBeInTheDocument();
    });

    it('[MIG-1] says why nothing can move, and offers no schedule', () => {
        render(
            <PackageMove
                {...moveProps(
                    {},
                    {
                        to_version: null,
                        refusal: {
                            code: 'package_migration_not_newer',
                            message:
                                'Subscribers can only move to the package’s published version, from an older version of the same package.',
                        },
                        subscribers: [],
                        effective_dates: [],
                        effective_on: null,
                    },
                )}
            />,
        );

        expect(
            screen.getByText(/can only move to the package/),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Schedule/ })).toBeNull();
    });

    it('[X-4] gives oversized names somewhere to go', () => {
        const { container } = render(
            <PackageMove
                {...moveProps(
                    {},
                    {
                        package: {
                            id: 'package-1',
                            link_name: 'synthetic-care',
                            name: LONG,
                        },
                        subscribers: [
                            moveCandidate({
                                client: { id: 'company-1', name: LONG },
                            }),
                        ],
                    },
                )}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
