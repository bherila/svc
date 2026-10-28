import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClientSettings from '@/pages/clients/settings';

const inertia = vi.hoisted(() => ({
    reload: vi.fn((options: { onFinish?: () => void }) => options.onFinish?.()),
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Head: () => null,
        router: inertia,
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            return {
                data,
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                errors: {},
                processing: false,
                reset: () => setData(initial),
            };
        },
    };
});
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/components/clients/portal-access', () => ({
    PortalAccess: () => null,
}));

const version = 'a'.repeat(64);
const props = {
    workspace: { id: 'workspace-1' },
    company: {
        id: 'client-1',
        name: 'Synthetic Client',
        billing_email: null,
        is_active: true,
    },
    project_create: {
        url: '/synthetic/project-create',
        company_id: 'client-1',
        expected_version: version,
    },
    projects: [
        {
            id: 'project-1',
            name: 'Synthetic Project',
            description: 'Existing description',
            repository: null,
            status: 'active',
            is_visible_to_client: true,
            version,
            update_url: '/synthetic/project-update',
            members_update_url: '/synthetic/project-members',
            members: [],
        },
    ],
    assignable: [{ id: 'member-1', name: 'Synthetic Member' }],
    portal: { members: [], add_url: '/synthetic/portal' },
};
let fetchMock: ReturnType<typeof vi.fn>;
function respond(status: number, body: unknown) {
    fetchMock.mockResolvedValueOnce(
        new Response(JSON.stringify(body), {
            status,
            headers: { 'Content-Type': 'application/json' },
        }),
    );
}
function request(index = 0) {
    const [url, init] = fetchMock.mock.calls[index] as [string, RequestInit];
    return {
        url,
        method: init.method,
        body: JSON.parse(String(init.body)),
        key: (init.headers as Record<string, string>)['Idempotency-Key'],
    };
}
beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    inertia.reload.mockClear();
});
afterEach(() => vi.unstubAllGlobals());

describe('project settings API mutations', () => {
    it('sends current project facts and version to the server-provided URL', async () => {
        respond(200, { data: {} });
        render(<ClientSettings {...props} />);
        fireEvent.change(screen.getByLabelText('Name of Synthetic Project'), {
            target: { value: 'Synthetic Rename' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save project' }));
        await waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(request()).toMatchObject({
            url: '/synthetic/project-update',
            method: 'PATCH',
            body: {
                name: 'Synthetic Rename',
                description: 'Existing description',
                repository: null,
                status: 'active',
                is_visible_to_client: true,
                expected_version: version,
                confirm: true,
            },
        });
        expect(request().body).not.toHaveProperty('lock_version');
    });

    it('keeps a create retry on the same receipt after an uncertain network result', async () => {
        fetchMock.mockRejectedValueOnce(
            new TypeError('synthetic network loss'),
        );
        respond(201, { data: {} });
        render(<ClientSettings {...props} />);
        fireEvent.change(screen.getByLabelText('New project'), {
            target: { value: 'Synthetic New Project' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add project' }));
        await screen.findByRole('alert');
        fireEvent.click(screen.getByRole('button', { name: 'Add project' }));
        await waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(request(0)).toEqual(request(1));
        expect(request().body).toEqual({
            name: 'Synthetic New Project',
            description: null,
            company_id: 'client-1',
            expected_version: version,
            confirm: true,
        });
        await waitFor(() =>
            expect(screen.getByLabelText('New project')).toHaveValue(''),
        );
    });

    it('shows a stale refusal and keeps the operator edits', async () => {
        respond(409, {
            message: 'This project has changed. Reload before saving.',
        });
        render(<ClientSettings {...props} />);
        fireEvent.change(screen.getByLabelText('Name of Synthetic Project'), {
            target: { value: 'Unsaved edit' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save project' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This project has changed',
        );
        expect(screen.getByLabelText('Name of Synthetic Project')).toHaveValue(
            'Unsaved edit',
        );
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('confirms a member grant and leaves the recorded role visible on failure', async () => {
        respond(422, { message: 'Synthetic grant refused.' });
        render(<ClientSettings {...props} />);
        fireEvent.change(screen.getByLabelText('Access for Synthetic Member'), {
            target: { value: 'viewer' },
        });
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Synthetic grant refused',
        );
        expect(
            screen.getByLabelText('Access for Synthetic Member'),
        ).toHaveValue('none');
        expect(request()).toMatchObject({
            url: '/synthetic/project-members',
            method: 'PUT',
            body: {
                user_id: 'member-1',
                role: 'viewer',
                expected_version: version,
                confirm: true,
            },
        });
    });
});
