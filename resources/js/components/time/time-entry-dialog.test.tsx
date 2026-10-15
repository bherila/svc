import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import { apiRequest } from '@/lib/api';
import type { CompanyOption, TimeEntry } from '@/types/time-sheet';

vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(() => new Promise(() => {})),
    newIdempotencyKey: () => crypto.randomUUID(),
}));

const inertia = vi.hoisted(() => ({
    patch: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
}));

const company: CompanyOption = {
    id: 'company-1',
    name: 'Synthetic Client',
    projects: [
        {
            id: 'project-1',
            name: 'Main project',
            can_log_time: true,
            log_time_url: '/synthetic/log-time',
            tasks: [
                { id: 'task-1', title: 'Existing task' },
                { id: 'task-2', title: 'Other task' },
            ],
        },
    ],
};

const timeEntry: TimeEntry = {
    id: 'entry-1',
    version: 'version-1',
    author_id: 'author-1',
    project_id: 'project-1',
    task_id: 'task-1',
    invoice_id: null,
    allocation_state: 'unallocated',
    web_url: '/synthetic/time-entry',
    update_url: '/synthetic/update-time',
    delete_url: '/synthetic/delete-time',
    worked_on: '2026-08-23',
    minutes: 60,
    description: 'Review implementation',
    client_visible_description: null,
    is_billable: true,
    is_deferred: false,
    is_visible_to_client: false,
    subcontractor_billing_mode: null,
    status: 'draft',
    project: { id: 'project-1', name: 'Main project' },
    task: { id: 'task-1', title: 'Existing task' },
    worker: 'Synthetic Manager',
    invoice: null,
    invoice_terms: null,
    can_edit: true,
    can_approve: true,
    unapprove_url: null,
};

describe('time entry dialog', () => {
    it('can return an attributed entry to no task', async () => {
        const user = userEvent.setup();
        render(
            <TimeEntryDialog
                timezone="America/Los_Angeles"
                company={company}
                entry={timeEntry}
                open
                onOpenChange={vi.fn()}
            />,
        );

        await user.click(screen.getByRole('combobox', { name: 'Task' }));
        await user.click(
            await screen.findByRole('option', { name: 'No task' }),
        );
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(vi.mocked(apiRequest)).toHaveBeenCalledOnce();
        expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(
            'PATCH',
            '/synthetic/update-time',
            expect.objectContaining({
                task_id: null,
                expected_version: 'version-1',
            }),
            expect.any(String),
        );
    });
    it.each([409, 422])(
        'shows an API refusal (%s) without closing the form',
        async (status) => {
            vi.mocked(apiRequest).mockResolvedValueOnce({
                ok: false,
                status,
                message: 'Synthetic refusal',
            });
            const onOpenChange = vi.fn();
            render(
                <TimeEntryDialog
                    timezone="UTC"
                    company={company}
                    entry={timeEntry}
                    open
                    onOpenChange={onOpenChange}
                />,
            );
            await userEvent.click(
                screen.getByRole('button', { name: 'Save changes' }),
            );
            expect(await screen.findByRole('alert')).toHaveTextContent(
                'Synthetic refusal',
            );
            expect(onOpenChange).not.toHaveBeenCalled();
            expect(inertia.reload).not.toHaveBeenCalled();
        },
    );

    it('logs through the supplied API URL and reloads props before closing', async () => {
        vi.mocked(apiRequest).mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: [],
        });
        const onOpenChange = vi.fn();
        render(
            <TimeEntryDialog
                timezone="UTC"
                company={company}
                entry={null}
                open
                onOpenChange={onOpenChange}
            />,
        );
        const user = userEvent.setup();
        await user.type(
            screen.getByLabelText('Work performed'),
            'Synthetic newly logged work',
        );
        await user.click(screen.getByRole('button', { name: 'Log time' }));
        expect(apiRequest).toHaveBeenCalledWith(
            'POST',
            '/synthetic/log-time',
            {
                entries: [
                    expect.objectContaining({
                        project_id: 'project-1',
                        description: 'Synthetic newly logged work',
                        minutes: 30,
                    }),
                ],
            },
            expect.any(String),
        );
        await waitFor(() => expect(inertia.reload).toHaveBeenCalledOnce());
        expect(onOpenChange).not.toHaveBeenCalled();
        inertia.reload.mock.calls[0][0].onFinish();
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });
});
