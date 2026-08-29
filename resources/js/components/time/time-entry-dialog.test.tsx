import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import type { CompanyOption, TimeEntry } from '@/types/time-sheet';

const inertia = vi.hoisted(() => ({
    patch: vi.fn(),
    post: vi.fn(),
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
    worked_on: '2026-08-23',
    minutes: 60,
    description: 'Review implementation',
    client_visible_description: null,
    is_billable: true,
    is_deferred: false,
    is_visible_to_client: false,
    status: 'draft',
    project: { id: 'project-1', name: 'Main project' },
    task: { id: 'task-1', title: 'Existing task' },
    worker: 'Synthetic Manager',
    invoice: null,
    can_edit: true,
    can_approve: true,
};

describe('time entry dialog', () => {
    it('can return an attributed entry to no task', async () => {
        const user = userEvent.setup();
        render(
            <TimeEntryDialog
                workspaceId="workspace-1"
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

        expect(inertia.patch).toHaveBeenCalledOnce();
        expect(inertia.patch).toHaveBeenCalledWith(
            '/workspaces/workspace-1/time-entries/entry-1',
            expect.objectContaining({
                task_id: null,
                expected_version: 'version-1',
            }),
            expect.any(Object),
        );
    });
});
