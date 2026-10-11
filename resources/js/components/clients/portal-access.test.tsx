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
});
