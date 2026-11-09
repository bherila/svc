import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';
import PackageRequests from '@/pages/packages/requests';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { requestRow, requestsProps } from '@/test/package-fixtures';
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
    router: { visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({
        props: {
            ...sharedPageProps({
                workspaceNavigation: workspaceNavigation({
                    current_client_id: null,
                    packages_href: '/workspaces/workspace-1/packages',
                }),
            }),
            requests_url: '/workspaces/workspace-1/packages/requests',
            open_requests_count: 2,
        },
    }),
}));
vi.mock('@/lib/api', () => ({ apiRequest: vi.fn() }));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn().mockResolvedValue(undefined),
}));

const LONG = 'Unbroken'.repeat(14);
const OK = { ok: true, status: 200, data: {} } as const;

describe('package requests', () => {
    beforeEach(() => vi.mocked(apiRequest).mockReset());

    it('adds a Sign-up requests tab with the open count', () => {
        render(<PackageRequests {...requestsProps()} />);
        const tabs = screen.getByRole('navigation', {
            name: 'Clients and packages',
        });

        expect(
            within(tabs).getByRole('link', { name: 'Sign-up requests · 2' }),
        ).toHaveAttribute('aria-current', 'page');
    });

    it('[PUB-6] shows what the visitor typed as literal text, never as markup', () => {
        const { container } = render(
            <PackageRequests
                {...requestsProps({
                    requests: [
                        requestRow({
                            untrusted: {
                                company_name: '<b>Bold Co</b>',
                                contact_name: '<i>Morgan</i>',
                                email: 'x@example.test',
                                phone: null,
                                answers: [
                                    {
                                        key: 'domain',
                                        question: 'Website address',
                                        answer: '<img src=x onerror=alert(1)>',
                                    },
                                ],
                            },
                        }),
                    ],
                })}
            />,
        );

        expect(screen.getAllByText('<b>Bold Co</b>').length).toBeGreaterThan(0);
        expect(
            screen.getByText('<img src=x onerror=alert(1)>'),
        ).toBeInTheDocument();
        expect(container.querySelector('b, i, img')).toBeNull();
    });

    it('[ADM-1] approves through the server’s URL with confirm, the version and a new client', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue(OK);
        render(<PackageRequests {...requestsProps()} />);

        expect(screen.getByText('Approving will')).toBeInTheDocument();
        await user.click(
            screen.getByRole('button', { name: /Approve and invite/ }),
        );
        const dialog = await screen.findByRole('alertdialog');
        // The trigger prints the label, not the stored value.
        expect(
            within(dialog).getByRole('combobox', { name: 'Client' }),
        ).toHaveTextContent('Create new: Lakeside Bakery');
        await user.click(
            within(dialog).getByRole('button', { name: 'Approve and invite' }),
        );

        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(1));
        expect(vi.mocked(apiRequest).mock.calls[0].slice(0, 3)).toEqual([
            'POST',
            '/api/v1/workspaces/ws-1/package-requests/request-1/approve',
            { expected_version: 'b'.repeat(64), confirm: true },
        ]);
    });

    it('[ADM-1] pre-selects the suggested client and sends its id', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue(OK);
        render(
            <PackageRequests
                {...requestsProps({
                    requests: [
                        requestRow({
                            suggested_client: {
                                id: 'client-1',
                                name: 'Pine Street Dental',
                            },
                        }),
                    ],
                })}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: /Approve and invite/ }),
        );
        const dialog = await screen.findByRole('alertdialog');

        expect(
            within(dialog).getByRole('combobox', { name: 'Client' }),
        ).toHaveTextContent('Pine Street Dental (existing)');
        await user.click(
            within(dialog).getByRole('button', { name: 'Approve and invite' }),
        );

        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(1));
        expect(vi.mocked(apiRequest).mock.calls[0][2]).toEqual({
            expected_version: 'b'.repeat(64),
            confirm: true,
            client_id: 'client-1',
        });
    });

    it('[ADM-1] declines with the optional reason', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue(OK);
        render(<PackageRequests {...requestsProps()} />);
        await user.click(screen.getByRole('button', { name: /Decline/ }));
        const dialog = await screen.findByRole('alertdialog');
        await user.type(
            within(dialog).getByLabelText('Reason (optional)'),
            'Outside our area',
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Decline' }),
        );

        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(1));
        expect(vi.mocked(apiRequest).mock.calls[0].slice(0, 3)).toEqual([
            'POST',
            '/api/v1/workspaces/ws-1/package-requests/request-1/decline',
            {
                expected_version: 'b'.repeat(64),
                confirm: true,
                reason: 'Outside our area',
            },
        ]);
    });

    it('shows a refusal in the dialog and a changed request as stale', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 409,
            message: 'changed',
        });
        render(<PackageRequests {...requestsProps()} />);
        await user.click(
            screen.getByRole('button', { name: /Approve and invite/ }),
        );
        const dialog = await screen.findByRole('alertdialog');
        await user.click(
            within(dialog).getByRole('button', { name: 'Approve and invite' }),
        );

        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'changed since you opened it',
        );
    });

    it('offers erase only on a decided request and sends confirm', async () => {
        const user = userEvent.setup();
        vi.mocked(apiRequest).mockResolvedValue(OK);
        render(
            <PackageRequests
                {...requestsProps({
                    requests: [
                        requestRow({
                            status: 'declined',
                            decided_at: '2026-11-09T10:00:00Z',
                            urls: {
                                approve: null,
                                decline: null,
                                erase: '/api/v1/workspaces/ws-1/package-requests/request-1/erase',
                            },
                        }),
                    ],
                })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Approve and invite/ }),
        ).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /Erase details/ }));
        const dialog = await screen.findByRole('alertdialog');
        await user.click(within(dialog).getByRole('button', { name: 'Erase' }));

        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(1));
        expect(vi.mocked(apiRequest).mock.calls[0][2]).toEqual({
            expected_version: 'b'.repeat(64),
            confirm: true,
        });
    });

    it('shows one badge per row, through statusLabel', () => {
        render(
            <PackageRequests
                {...requestsProps({
                    requests: [
                        requestRow(),
                        requestRow({ id: 'request-2', status: 'declined' }),
                    ],
                })}
            />,
        );
        const table = screen.getByRole('table');

        expect(within(table).getAllByText('New')).toHaveLength(1);
        expect(within(table).getAllByText('Declined')).toHaveLength(1);
    });

    it('reads an erased request as a stub', () => {
        render(
            <PackageRequests
                {...requestsProps({
                    requests: [
                        requestRow({
                            status: 'approved',
                            untrusted: null,
                            erased_at: '2026-11-09T11:00:00Z',
                            urls: { approve: null, decline: null, erase: null },
                        }),
                    ],
                })}
            />,
        );

        expect(screen.getAllByText('Erased request').length).toBeGreaterThan(0);
        expect(screen.getByText(/details were erased/)).toBeInTheDocument();
    });

    it('[X-4] survives oversized names, emails and answers', () => {
        const { container } = render(
            <PackageRequests
                {...requestsProps({
                    requests: [
                        requestRow({
                            untrusted: {
                                company_name: LONG,
                                contact_name: LONG,
                                email: `${LONG}@example.test`,
                                phone: LONG,
                                answers: [
                                    {
                                        key: 'domain',
                                        question: LONG,
                                        answer: LONG.repeat(5),
                                    },
                                ],
                            },
                        }),
                    ],
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
