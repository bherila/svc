import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PortalAccess } from '@/components/clients/portal-access';
import type { PortalAccessProps } from '@/components/clients/portal-access';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const inertia = vi.hoisted(() => ({
    delete: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ router: inertia }));

beforeEach(() => {
    inertia.delete.mockReset();
    inertia.post.mockReset();
    inertia.put.mockReset();
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
        add_url: '/synthetic/portal-access',
        members: [
            {
                id: 'contact-1',
                name: 'SyntheticContactName'.repeat(20),
                email: `${'synthetic'.repeat(30)}@client.example.test`,
                scope: 'projects',
                projects: ['project-beta'],
                url: '/synthetic/portal-access/contact-1',
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
                    portal: {
                        add_url: '/synthetic/portal-access',
                        members: [],
                    },
                })}
            />,
        );

        expect(
            screen.getByText("Nobody can use this client's portal yet."),
        ).toBeInTheDocument();
    });

    it('saves a contact to the server URL with only the chosen projects', async () => {
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.click(screen.getByRole('checkbox', { name: 'Alpha' }));
        await user.click(screen.getByRole('button', { name: 'Save access' }));

        expect(inertia.put).toHaveBeenCalledWith(
            '/synthetic/portal-access/contact-1',
            { scope: 'projects', projects: ['project-beta', 'project-alpha'] },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('sends no projects for access to the whole client', async () => {
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.selectOptions(
            screen.getByRole('combobox', {
                name: `Portal access for ${'SyntheticContactName'.repeat(20)}`,
            }),
            'company',
        );
        await user.click(screen.getByRole('button', { name: 'Save access' }));

        expect(inertia.put).toHaveBeenCalledWith(
            '/synthetic/portal-access/contact-1',
            { scope: 'company', projects: [] },
            expect.anything(),
        );
    });

    it('removes a contact only after confirming', async () => {
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.click(screen.getByRole('button', { name: 'Remove' }));
        expect(inertia.delete).not.toHaveBeenCalled();
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'Synthetic Client portal',
        );
        await user.click(screen.getByRole('button', { name: 'Remove access' }));

        expect(inertia.delete).toHaveBeenCalledWith(
            '/synthetic/portal-access/contact-1',
            expect.anything(),
        );
    });

    it('adds a contact by email and shows why the server refused', async () => {
        inertia.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onError: (errors: Record<string, string>) => void },
            ) =>
                options.onError({
                    email: 'Nobody signs in with that address yet. Ask them to sign in once, then add them.',
                }),
        );
        const user = userEvent.setup();
        render(<PortalAccess {...props()} />);

        await user.type(
            screen.getByLabelText('Add a contact'),
            'new@client.example.test',
        );
        await user.click(
            screen.getByRole('button', { name: 'Give portal access' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/synthetic/portal-access',
            {
                email: 'new@client.example.test',
                scope: 'company',
                projects: [],
            },
            expect.anything(),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Nobody signs in with that address yet.',
        );
    });

    it('offers selected projects only when the client has some', () => {
        render(
            <PortalAccess
                {...props({
                    projects: [],
                    portal: {
                        add_url: '/synthetic/portal-access',
                        members: [],
                    },
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
                    add_url: '/synthetic/portal-access',
                    members: [],
                    invite_url: '/synthetic/portal-invitations',
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
                            resend_url:
                                '/synthetic/portal-invitations/invitation-1/resend',
                            revoke_url:
                                '/synthetic/portal-invitations/invitation-1',
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
                            revoke_url:
                                '/synthetic/portal-invitations/invitation-2',
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

        it('sends an invitation again and withdraws one only after confirming', async () => {
            inertia.post.mockImplementationOnce(
                (
                    _url: string,
                    _data: unknown,
                    options: { onFinish: () => void },
                ) => options.onFinish(),
            );
            const user = userEvent.setup();
            render(<PortalAccess {...invitationProps()} />);

            await user.click(
                screen.getByRole('button', { name: 'Send again' }),
            );
            expect(inertia.post).toHaveBeenCalledWith(
                '/synthetic/portal-invitations/invitation-1/resend',
                {},
                expect.objectContaining({ preserveScroll: true }),
            );

            await user.click(screen.getByRole('button', { name: 'Withdraw' }));
            expect(inertia.delete).not.toHaveBeenCalled();
            await user.click(
                screen.getByRole('button', { name: 'Withdraw invitation' }),
            );
            expect(inertia.delete).toHaveBeenCalledWith(
                '/synthetic/portal-invitations/invitation-1',
                expect.anything(),
            );
        });

        it('dismisses a not-applied invitation, which cannot be sent again', async () => {
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
            expect(inertia.delete).toHaveBeenCalledWith(
                '/synthetic/portal-invitations/invitation-2',
                expect.anything(),
            );
        });

        it('offers to send an invitation when nobody signs in with the address', async () => {
            inertia.post.mockImplementationOnce(
                (
                    _url: string,
                    _data: unknown,
                    options: {
                        onError: (errors: Record<string, string>) => void;
                        onFinish: () => void;
                    },
                ) => {
                    options.onError({
                        invite: 'Nobody signs in with that address yet. Send them an invitation, and they get this access when they first sign in.',
                    });
                    options.onFinish();
                },
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
                screen.getByRole('button', { name: 'Send invitation' }),
            );

            expect(inertia.post).toHaveBeenLastCalledWith(
                '/synthetic/portal-invitations',
                {
                    email: 'new@client.example.test',
                    scope: 'company',
                    projects: [],
                },
                expect.objectContaining({ preserveScroll: true }),
            );
        });

        it('never offers an invitation while invitations are off', async () => {
            inertia.post.mockImplementationOnce(
                (
                    _url: string,
                    _data: unknown,
                    options: {
                        onError: (errors: Record<string, string>) => void;
                    },
                ) => options.onError({ invite: 'Synthetic refusal.' }),
            );
            const user = userEvent.setup();
            render(<PortalAccess {...props()} />);

            await user.type(
                screen.getByLabelText('Add a contact'),
                'new@client.example.test',
            );
            await user.click(
                screen.getByRole('button', { name: 'Give portal access' }),
            );

            expect(
                screen.queryByRole('button', { name: 'Send invitation' }),
            ).not.toBeInTheDocument();
            expect(screen.queryByText('Invitations')).not.toBeInTheDocument();
        });
    });
});
