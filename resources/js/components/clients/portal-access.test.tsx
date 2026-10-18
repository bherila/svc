import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PortalAccess } from '@/components/clients/portal-access';
import type { PortalAccessProps } from '@/components/clients/portal-access';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const inertia = vi.hoisted(() => ({
    reload: vi.fn((options?: { onFinish?: () => void }) =>
        options?.onFinish?.(),
    ),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

const api = '/api/v1/workspaces/workspace-1/clients/client-1';
const version = 'a'.repeat(64);
let fetchMock: ReturnType<typeof vi.fn>;

function respond(...answers: [number, unknown][]) {
    for (const [status, body] of answers) {
        fetchMock.mockResolvedValueOnce(
            new Response(JSON.stringify(body), {
                status,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
    }
}

function request(index = 0) {
    const [url, init] = fetchMock.mock.calls[index] as [string, RequestInit];

    return {
        url,
        method: init.method,
        body: JSON.parse(String(init.body)) as Record<string, unknown>,
        key: (init.headers as Record<string, string>)['Idempotency-Key'],
    };
}

beforeEach(() => {
    inertia.reload.mockClear();
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const props = (
    overrides: Partial<PortalAccessProps> = {},
): PortalAccessProps => ({
    clientName: 'Synthetic Client',
    projects: [
        { id: 'project-alpha', name: 'Alpha' },
        { id: 'project-beta', name: 'SyntheticProjectName'.repeat(20) },
    ],
    portal: {
        add_url: `${api}/portal-access`,
        members: [
            {
                id: 'contact-1',
                name: 'SyntheticContactName'.repeat(20),
                email: `${'synthetic'.repeat(30)}@client.example.test`,
                scope: 'projects',
                projects: [
                    {
                        id: 'project-beta',
                        name: 'SyntheticProjectName'.repeat(20),
                        href: '/api/v1/workspaces/workspace-1/projects/project-beta',
                    },
                ],
                version,
                update_href: `${api}/portal-access/contact-1`,
                revoke_href: `${api}/portal-access/contact-1`,
            },
        ],
    },
    ...overrides,
});

describe('portal access', () => {
    it('lists each contact with what they reach, and wraps long names and addresses', () => {
        const { container } = render(<PortalAccess {...props()} />);

        const select = screen.getByRole('combobox', {
            name: `Portal access for ${'SyntheticContactName'.repeat(20)}`,
        });
        expect(select).toHaveValue('projects');
        const item = select.closest('li') as HTMLElement;
        expect(
            within(item).getByRole('checkbox', { name: 'Alpha' }),
        ).not.toBeChecked();
        expect(
            within(item).getByRole('checkbox', {
                name: 'SyntheticProjectName'.repeat(20),
            }),
        ).toBeChecked();
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('says so when nobody can use the portal', () => {
        render(
            <PortalAccess
                {...props({
                    portal: { add_url: `${api}/portal-access`, members: [] },
                })}
            />,
        );

        expect(
            screen.getByText("Nobody can use this client's portal yet."),
        ).toBeInTheDocument();
    });

    it('saves a contact through the API with its version, then reloads', async () => {
        respond([200, { data: {} }]);
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.click(screen.getByRole('checkbox', { name: 'Alpha' }));
        await user.click(screen.getByRole('button', { name: 'Save access' }));

        await waitFor(() => expect(inertia.reload).toHaveBeenCalledOnce());
        const sent = request();
        expect([sent.method, sent.url]).toEqual([
            'PATCH',
            `${api}/portal-access/contact-1`,
        ]);
        expect(sent.body).toEqual({
            scope: 'projects',
            projects: ['project-beta', 'project-alpha'],
            expected_version: version,
            confirm: true,
        });
        expect(sent.key).toEqual(expect.any(String));
    });

    it('sends no projects for access to the whole client', async () => {
        respond([200, { data: {} }]);
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.selectOptions(
            screen.getByRole('combobox', {
                name: `Portal access for ${'SyntheticContactName'.repeat(20)}`,
            }),
            'company',
        );
        await user.click(screen.getByRole('button', { name: 'Save access' }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
        expect(request().body).toMatchObject({
            scope: 'company',
            projects: [],
        });
    });

    it('shows a stale version refusal on the row and keeps the page', async () => {
        respond([
            409,
            {
                message:
                    'Portal access changed. Read it again before confirming.',
            },
        ]);
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.click(screen.getByRole('button', { name: 'Save access' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Portal access changed. Read it again before confirming.',
        );
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('removes a contact only after confirming', async () => {
        respond([200, { data: { revoked: true } }]);
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.click(screen.getByRole('button', { name: 'Remove' }));
        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'Synthetic Client portal',
        );
        await user.click(screen.getByRole('button', { name: 'Remove access' }));

        await waitFor(() => expect(inertia.reload).toHaveBeenCalledOnce());
        expect(request()).toMatchObject({
            method: 'DELETE',
            url: `${api}/portal-access/contact-1`,
            body: { expected_version: version, confirm: true },
        });
    });

    it('adds a contact by email and shows why the server refused', async () => {
        respond([
            422,
            {
                message: 'The given data was invalid.',
                errors: {
                    email: [
                        'Nobody signs in with that address yet. Ask them to sign in once, then add them.',
                    ],
                },
            },
        ]);
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.type(
            screen.getByLabelText('Add a contact'),
            'new@client.example.test',
        );
        await user.click(
            screen.getByRole('button', { name: 'Give portal access' }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Nobody signs in with that address yet.',
        );
        expect(request()).toMatchObject({
            method: 'POST',
            url: `${api}/portal-access`,
            body: {
                email: 'new@client.example.test',
                scope: 'company',
                projects: [],
                confirm: true,
            },
        });
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('clears the form once a contact is added', async () => {
        respond([201, { data: {} }]);
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.type(
            screen.getByLabelText('Add a contact'),
            'new@client.example.test',
        );
        await user.click(
            screen.getByRole('button', { name: 'Give portal access' }),
        );

        await waitFor(() =>
            expect(screen.getByLabelText('Add a contact')).toHaveValue(''),
        );
        expect(inertia.reload).toHaveBeenCalledOnce();
    });

    it('offers selected projects only when the client has some', () => {
        render(
            <PortalAccess
                {...props({
                    projects: [],
                    portal: { add_url: `${api}/portal-access`, members: [] },
                })}
            />,
        );

        expect(
            screen.getByRole('option', { name: 'Selected projects' }),
        ).toBeDisabled();
    });

    describe('invitations', () => {
        const longEmail = `${'invited'.repeat(30)}@client.example.test`;
        const longLink = `https://identity.example.test/invitations/${'token'.repeat(40)}`;
        const invitationProps = () =>
            props({
                portal: {
                    add_url: `${api}/portal-access`,
                    members: [],
                    invite_url: `${api}/portal-invitations`,
                    invitations: [
                        {
                            id: 'invitation-1',
                            email: longEmail,
                            scope: 'projects',
                            projects: ['project-alpha'],
                            status: 'pending',
                            reason: null,
                            expires_at: '2026-10-19T12:00:00Z',
                            email_sent: true,
                            link: longLink,
                            resend_url: `${api}/portal-invitations/invitation-1/resend`,
                            revoke_url: `${api}/portal-invitations/invitation-1`,
                        },
                        {
                            id: 'invitation-2',
                            email: 'late@client.example.test',
                            scope: 'company',
                            projects: [],
                            status: 'not_applied',
                            reason: 'None of the invited projects belong to this client any more, so nothing was given.',
                            expires_at: null,
                            email_sent: true,
                            link: null,
                            resend_url: null,
                            revoke_url: `${api}/portal-invitations/invitation-2`,
                        },
                    ],
                },
            });

        it('lists open and not-applied invitations with a copyable link, and wraps long ones', () => {
            const { container } = render(
                <PortalAccess {...invitationProps()} />,
            );

            expect(screen.getByText(longEmail)).toBeInTheDocument();
            expect(screen.getByLabelText('Invitation link')).toHaveValue(
                longLink,
            );
            expect(screen.getAllByLabelText('Invitation link')).toHaveLength(1);
            expect(
                screen.getByText(/None of the invited projects/),
            ).toBeInTheDocument();
            expect(screen.getByText(/^Not applied/)).toBeInTheDocument();
            expect(horizontalOverflowRisks(container)).toEqual([]);
        });

        it('follows server-provided invitation page links', () => {
            const page = invitationProps();
            page.portal.invitation_pagination = {
                next_url: '/synthetic/settings?invitation_page=3',
                previous_url: '/synthetic/settings?invitation_page=1',
            };
            const { container } = render(<PortalAccess {...page} />);
            const navigation = screen.getByRole('navigation', {
                name: 'Invitation pages',
            });
            expect(
                within(navigation).getByRole('link', {
                    name: 'Older invitations',
                }),
            ).toHaveAttribute(
                'href',
                page.portal.invitation_pagination.next_url,
            );
            expect(
                within(navigation).getByRole('link', {
                    name: 'Newer invitations',
                }),
            ).toHaveAttribute(
                'href',
                page.portal.invitation_pagination.previous_url,
            );
            expect(horizontalOverflowRisks(container)).toEqual([]);
        });

        it('offers a provider withdrawal retry without exposing an admission link', async () => {
            respond([200, { data: {} }]);
            const page = invitationProps();
            page.portal.invitations = [
                {
                    ...page.portal.invitations![0],
                    status: 'revoked',
                    link: null,
                    resend_url: null,
                    reason: 'Portal access is withdrawn. The identity provider withdrawal has not finished; try again.',
                },
            ];
            const { container } = render(<PortalAccess {...page} />);
            expect(screen.getByText('Revoked')).toBeInTheDocument();
            expect(
                screen.queryByLabelText('Invitation link'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Send again' }),
            ).not.toBeInTheDocument();
            await userEvent.click(
                screen.getByRole('button', { name: 'Retry withdrawal' }),
            );
            expect(fetchMock).not.toHaveBeenCalled();
            await userEvent.click(
                within(screen.getByRole('alertdialog')).getByRole('button', {
                    name: 'Withdraw invitation',
                }),
            );
            await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
            expect(request()).toMatchObject({
                method: 'DELETE',
                url: page.portal.invitations[0].revoke_url,
                body: { confirm: true },
            });
            expect(horizontalOverflowRisks(container)).toEqual([]);
        });

        it('sends an invitation again and withdraws one only after confirming', async () => {
            respond([200, { data: {} }], [200, { data: {} }]);
            const user = userEvent.setup();
            render(<PortalAccess {...invitationProps()} />);

            await user.click(
                screen.getByRole('button', { name: 'Send again' }),
            );
            await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
            expect(request()).toMatchObject({
                method: 'POST',
                url: `${api}/portal-invitations/invitation-1/resend`,
                body: { confirm: true },
            });

            await user.click(screen.getByRole('button', { name: 'Withdraw' }));
            expect(fetchMock).toHaveBeenCalledOnce();
            await user.click(
                screen.getByRole('button', { name: 'Withdraw invitation' }),
            );
            await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
            expect(request(1)).toMatchObject({
                method: 'DELETE',
                url: `${api}/portal-invitations/invitation-1`,
            });
        });

        it('retries a lost resend with the same key, so it is not sent twice', async () => {
            fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
            respond([200, { data: {} }]);
            const user = userEvent.setup();
            render(<PortalAccess {...invitationProps()} />);

            await user.click(
                screen.getByRole('button', { name: 'Send again' }),
            );
            expect(await screen.findByRole('alert')).toHaveTextContent(
                'SVC could not be reached.',
            );
            await user.click(
                screen.getByRole('button', { name: 'Send again' }),
            );

            await waitFor(() => expect(inertia.reload).toHaveBeenCalledOnce());
            expect(request(1).key).toBe(request(0).key);
        });

        it('dismisses a not-applied invitation, which cannot be sent again', async () => {
            respond([200, { data: {} }]);
            const user = userEvent.setup();
            render(<PortalAccess {...invitationProps()} />);

            expect(
                screen.getAllByRole('button', { name: 'Send again' }),
            ).toHaveLength(1);
            await user.click(screen.getByRole('button', { name: 'Dismiss' }));
            await user.click(
                within(screen.getByRole('alertdialog')).getByRole('button', {
                    name: 'Dismiss',
                }),
            );
            await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
            expect(request()).toMatchObject({
                method: 'DELETE',
                url: `${api}/portal-invitations/invitation-2`,
            });
        });

        it('offers to send an invitation when nobody signs in with the address', async () => {
            respond(
                [
                    422,
                    {
                        message: 'The given data was invalid.',
                        errors: {
                            invite: [
                                'Nobody signs in with that address yet. Send them an invitation, and they get this access when they first sign in.',
                            ],
                        },
                    },
                ],
                [201, { data: {} }],
            );
            const user = userEvent.setup();
            render(<PortalAccess {...invitationProps()} />);

            expect(
                screen.queryByRole('button', { name: 'Send invitation' }),
            ).not.toBeInTheDocument();
            await user.type(
                screen.getByLabelText('Add a contact'),
                'new@client.example.test',
            );
            await user.click(
                screen.getByRole('button', { name: 'Give portal access' }),
            );
            await user.click(
                await screen.findByRole('button', { name: 'Send invitation' }),
            );

            await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
            expect(request(1)).toMatchObject({
                method: 'POST',
                url: `${api}/portal-invitations`,
                body: {
                    email: 'new@client.example.test',
                    scope: 'company',
                    projects: [],
                    confirm: true,
                },
            });
        });

        it('never offers an invitation while invitations are off', async () => {
            respond([
                422,
                {
                    message: 'The given data was invalid.',
                    errors: { invite: ['Synthetic refusal.'] },
                },
            ]);
            const user = userEvent.setup();
            render(<PortalAccess {...props()} />);

            await user.type(
                screen.getByLabelText('Add a contact'),
                'new@client.example.test',
            );
            await user.click(
                screen.getByRole('button', { name: 'Give portal access' }),
            );

            expect(await screen.findByRole('alert')).toHaveTextContent(
                'Synthetic refusal.',
            );
            expect(
                screen.queryByRole('button', { name: 'Send invitation' }),
            ).not.toBeInTheDocument();
            expect(screen.queryByText('Invitations')).not.toBeInTheDocument();
        });
    });
});
