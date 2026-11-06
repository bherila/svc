import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SharedProjectInvitation from '@/pages/shared-project-invitations/show';
import SharedProjectsIndex from '@/pages/shared-projects/index';
import SharedProjectShow from '@/pages/shared-projects/show';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type {
    SharedInvitationProps,
    SharedProjectIndexProps,
    SharedProjectShowProps,
    SharedTask,
} from '@/types/shared-projects';

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
    router: inertia,
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        }),
    }),
}));

const LONG = 'Unbroken'.repeat(14);

function byTest(name: string): HTMLElement {
    const found = document.querySelector<HTMLElement>(`[data-test="${name}"]`);

    if (found === null) {
        throw new Error(`No element marked ${name}.`);
    }

    return found;
}

function task(overrides: Partial<SharedTask> = {}): SharedTask {
    return {
        id: 'task-1',
        title: 'Shared synthetic task',
        description: null,
        status: 'in_progress',
        shared_with_participants: true,
        starts_on: null,
        due_on: '2026-11-20',
        completed_at: null,
        assignees: [{ name: 'Ana Example', workspace_name: 'Partner Alpha' }],
        can_comment: true,
        comments_url:
            '/api/v1/workspaces/w/shared-projects/p/tasks/task-1/comments',
        assign_url: null,
        ...overrides,
    };
}

function hostProps(
    overrides: Partial<SharedProjectShowProps> = {},
): SharedProjectShowProps {
    return {
        workspaceName: 'Host Studio',
        project: {
            id: 'project-1',
            name: 'Synthetic Shared Project',
            status: 'active',
            side: 'host',
            host_workspace_name: 'Host Studio',
            client_name: 'Synthetic Client',
            viewer: {
                sees_whole_project: true,
                can_assign: true,
                can_manage_participants: true,
                can_act_as_participant: false,
            },
            participants: [
                {
                    id: 'participation-1',
                    workspace_name: 'Partner Alpha',
                    accepted_at: '2026-11-08T12:00:00Z',
                    revoke_url:
                        '/workspaces/w/shared-projects/p/participants/1',
                },
            ],
            invitations: [
                {
                    id: 'invitation-1',
                    email: 'new.person@example.test',
                    expires_at: '2026-11-22T12:00:00Z',
                    accept_url: '/shared-project-invitations/invitation-1',
                    revoke_url: '/workspaces/w/shared-projects/p/invitations/1',
                },
            ],
            assignee_options: [
                {
                    user_id: 'user-1',
                    name: 'Ana Example',
                    workspace_id: 'workspace-a',
                    workspace_name: 'Partner Alpha',
                },
                {
                    user_id: 'user-2',
                    name: 'Hana Host',
                    workspace_id: 'workspace-h',
                    workspace_name: 'Host Studio',
                },
            ],
        },
        tasks: [
            task({
                assign_url:
                    '/api/v1/workspaces/w/shared-projects/p/tasks/task-1/assignees',
                assignees: [
                    {
                        name: 'Ana Example',
                        workspace_name: 'Partner Alpha',
                        user_id: 'user-1',
                        workspace_id: 'workspace-a',
                    },
                ],
            }),
        ],
        tasks_next_cursor: null,
        tasks_url: '/api/v1/workspaces/w/shared-projects/p/tasks',
        urls: {
            index: '/workspaces/w/shared-projects',
            invite: '/workspaces/w/shared-projects/p/invitations',
            leave: null,
            link: null,
            host_project: '/workspaces/w/clients/c/projects/p',
        },
        local_project_options: [],
        ...overrides,
    };
}

function participantProps(): SharedProjectShowProps {
    return {
        workspaceName: 'Partner Alpha',
        project: {
            id: 'project-1',
            name: 'Synthetic Shared Project',
            status: 'active',
            side: 'participant',
            host_workspace_name: 'Host Studio',
            viewer: {
                sees_whole_project: true,
                can_assign: false,
                can_manage_participants: false,
                can_act_as_participant: true,
            },
            participation: { accepted_at: '2026-11-08T12:00:00Z' },
            local_project: { id: 'local-1', name: 'Our own project' },
        },
        tasks: [task()],
        tasks_next_cursor: null,
        tasks_url: '/api/v1/workspaces/w/shared-projects/p/tasks',
        urls: {
            index: '/workspaces/w/shared-projects',
            invite: null,
            leave: '/workspaces/w/shared-projects/p/leave',
            link: '/workspaces/w/shared-projects/p/local-project',
            host_project: null,
        },
        local_project_options: [
            { id: 'local-1', name: 'Our own project' },
            { id: 'local-2', name: 'Another own project' },
        ],
    };
}

beforeEach(() => {
    api.apiRequest.mockReset();
    refresh.refreshPageProps.mockReset();
    refresh.refreshPageProps.mockResolvedValue(undefined);
    inertia.visit.mockReset();
});

describe('Shared project, as the host', () => {
    it('shows who takes part, who is invited and the tasks with each assignee’s workspace', () => {
        render(<SharedProjectShow {...hostProps()} />);

        expect(
            screen.getByText(/Hosted by you for Synthetic Client/),
        ).toBeInTheDocument();
        const participants = byTest('shared-participants');

        expect(
            within(participants).getByText('Partner Alpha'),
        ).toBeInTheDocument();
        expect(
            within(participants).getByText('new.person@example.test'),
        ).toBeInTheDocument();
        // A stored status is read as words, and the person is shown with their workspace.
        expect(screen.getByText(/In progress/)).toBeInTheDocument();
        expect(screen.queryByText('in_progress')).not.toBeInTheDocument();
        expect(
            screen.getByText('Ana Example (Partner Alpha)'),
        ).toBeInTheDocument();
    });

    it('invites by address and shows the link to send', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: true,
            status: 201,
            data: {
                accept_url:
                    'https://svc.example.test/shared-project-invitations/abc',
            },
        });
        render(<SharedProjectShow {...hostProps()} />);

        await user.type(
            screen.getByLabelText('Invite by email address'),
            'Ines@example.test',
        );
        await user.click(screen.getByRole('button', { name: 'Invite' }));

        await waitFor(() => expect(api.apiRequest).toHaveBeenCalledTimes(1));
        expect(api.apiRequest).toHaveBeenCalledWith(
            'POST',
            '/workspaces/w/shared-projects/p/invitations',
            { email: 'Ines@example.test' },
        );
        expect(await screen.findByLabelText('Send them this link')).toHaveValue(
            'https://svc.example.test/shared-project-invitations/abc',
        );
        expect(refresh.refreshPageProps).toHaveBeenCalled();
    });

    it('says why an invitation was refused', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'The email field must be a valid email address.',
            errors: {
                email: ['The email field must be a valid email address.'],
            },
        });
        render(<SharedProjectShow {...hostProps()} />);

        await user.type(
            screen.getByLabelText('Invite by email address'),
            'nope',
        );
        await user.click(screen.getByRole('button', { name: 'Invite' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'valid email',
        );
    });

    it('removes a participant only after confirming', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 204, data: null });
        render(<SharedProjectShow {...hostProps()} />);

        await user.click(screen.getByRole('button', { name: 'Remove…' }));

        expect(api.apiRequest).not.toHaveBeenCalled();
        await user.click(await screen.findByRole('button', { name: 'Remove' }));

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'DELETE',
                '/workspaces/w/shared-projects/p/participants/1',
                {},
            ),
        );
        expect(refresh.refreshPageProps).toHaveBeenCalled();
    });

    it('assigns across workspaces from the options the server sent', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: {} });
        render(<SharedProjectShow {...hostProps()} />);

        await user.click(screen.getByRole('button', { name: 'Assign…' }));
        const host = await screen.findByLabelText('Hana Host');
        await user.click(host);
        await user.click(
            screen.getByRole('button', { name: 'Save assignees' }),
        );

        await waitFor(() => expect(api.apiRequest).toHaveBeenCalledTimes(1));
        expect(api.apiRequest).toHaveBeenCalledWith(
            'PUT',
            '/api/v1/workspaces/w/shared-projects/p/tasks/task-1/assignees',
            {
                assignees: [
                    { user_id: 'user-1', workspace_id: 'workspace-a' },
                    { user_id: 'user-2', workspace_id: 'workspace-h' },
                ],
            },
        );
    });

    it('offers no assignment where the server sent no URL', () => {
        render(
            <SharedProjectShow
                {...hostProps({ tasks: [task({ assign_url: null })] })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Assign…' }),
        ).not.toBeInTheDocument();
    });
});

describe('Shared project, as a participant', () => {
    it('shows the host, the tasks and its own side, and no money or who else takes part', () => {
        render(<SharedProjectShow {...participantProps()} />);

        expect(screen.getByText(/Hosted by Host Studio/)).toBeInTheDocument();
        expect(screen.getByText('Shared synthetic task')).toBeInTheDocument();
        expect(byTest('shared-participation')).toBeInTheDocument();
        expect(
            document.querySelector('[data-test="shared-participants"]'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/[$€£]\s?\d/)).not.toBeInTheDocument();
    });

    it('links one of its own projects, by name', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 204, data: null });
        render(<SharedProjectShow {...participantProps()} />);

        // The control shows the project’s name, never its id.
        expect(screen.getByText('Our own project')).toBeInTheDocument();
        await user.click(screen.getByLabelText('Your own project'));
        await user.click(
            await screen.findByRole('option', { name: 'Another own project' }),
        );
        await user.click(screen.getByRole('button', { name: 'Save link' }));

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'PUT',
                '/workspaces/w/shared-projects/p/local-project',
                { local_project_id: 'local-2' },
            ),
        );
    });

    it('leaves after confirming and goes back to the list', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 204, data: null });
        render(<SharedProjectShow {...participantProps()} />);

        await user.click(
            screen.getByRole('button', { name: 'Leave this project…' }),
        );
        await user.click(await screen.findByRole('button', { name: 'Leave' }));

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/workspaces/w/shared-projects/p/leave',
                {},
            ),
        );
        expect(inertia.visit).toHaveBeenCalledWith(
            '/workspaces/w/shared-projects',
        );
    });

    it('offers neither leaving nor linking to someone who may not', () => {
        const props = participantProps();
        render(
            <SharedProjectShow
                {...props}
                urls={{ ...props.urls, leave: null, link: null }}
                project={{
                    ...props.project,
                    viewer: {
                        ...props.project.viewer,
                        can_act_as_participant: false,
                    },
                    local_project: undefined,
                }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Leave this project…' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Your own project'),
        ).not.toBeInTheDocument();
    });

    it('reads and adds comments on a task', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockImplementation((method: string) =>
            Promise.resolve(
                method === 'GET'
                    ? {
                          ok: true,
                          status: 200,
                          data: [
                              {
                                  id: 'c1',
                                  body: 'Host shared comment',
                                  author_name: 'Hana Host',
                                  author_workspace_name: 'Host Studio',
                                  shared_with_participants: true,
                                  created_at: '2026-11-09T10:00:00Z',
                              },
                          ],
                      }
                    : {
                          ok: true,
                          status: 201,
                          data: {
                              id: 'c2',
                              body: 'Our reply',
                              author_name: 'Ana Example',
                              author_workspace_name: 'Partner Alpha',
                              shared_with_participants: true,
                              created_at: '2026-11-09T11:00:00Z',
                          },
                      },
            ),
        );
        render(<SharedProjectShow {...participantProps()} />);

        await user.click(screen.getByRole('button', { name: 'Discussion' }));
        expect(
            await screen.findByText('Host shared comment'),
        ).toBeInTheDocument();
        await user.type(screen.getByLabelText('Add a comment'), 'Our reply');
        await user.click(screen.getByRole('button', { name: 'Comment' }));

        expect(await screen.findByText('Our reply')).toBeInTheDocument();
        expect(api.apiRequest).toHaveBeenCalledWith(
            'POST',
            '/api/v1/workspaces/w/shared-projects/p/tasks/task-1/comments',
            { body: 'Our reply' },
        );
    });

    it('offers no comment box where the server says the viewer cannot comment', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: [] });
        const props = participantProps();
        render(
            <SharedProjectShow
                {...props}
                tasks={[task({ can_comment: false })]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Discussion' }));

        expect(await screen.findByText('No comments yet.')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Add a comment'),
        ).not.toBeInTheDocument();
    });

    it('says plainly when nothing is shared yet', () => {
        render(<SharedProjectShow {...participantProps()} tasks={[]} />);

        expect(
            screen.getByText('Nothing is shared with you here yet.'),
        ).toBeInTheDocument();
    });
});

describe('layout', () => {
    it('does not overflow with deliberately oversized names', () => {
        const props = hostProps();
        const { container } = render(
            <SharedProjectShow
                {...props}
                project={{
                    ...props.project,
                    name: LONG,
                    client_name: LONG,
                    participants: [
                        {
                            ...props.project.participants![0],
                            workspace_name: LONG,
                        },
                    ],
                    invitations: [
                        {
                            ...props.project.invitations![0],
                            email: `${LONG}@example.test`,
                        },
                    ],
                }}
                tasks={[
                    task({
                        title: LONG,
                        assignees: [{ name: LONG, workspace_name: LONG }],
                    }),
                ]}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

describe('Shared projects list', () => {
    const list: SharedProjectIndexProps = {
        workspaceName: 'Host Studio',
        hosted: [
            {
                id: 'p1',
                name: 'Hosted synthetic project',
                client_name: 'Synthetic Client',
                web_url: '/workspaces/w/shared-projects/p1',
            },
        ],
        participating: [
            {
                id: 'p2',
                name: 'Joined synthetic project',
                host_workspace_name: 'Other Host',
                web_url: '/workspaces/w/shared-projects/p2',
            },
        ],
    };

    it('lists what the workspace hosts and takes part in, as whole-row links', () => {
        render(<SharedProjectsIndex {...list} />);

        expect(
            screen.getByRole('link', { name: /Hosted synthetic project/ }),
        ).toHaveAttribute('href', '/workspaces/w/shared-projects/p1');
        expect(
            screen.getByRole('link', { name: /Joined synthetic project/ }),
        ).toHaveTextContent('Hosted by Other Host');
    });

    it('teaches what an empty list means', () => {
        render(
            <SharedProjectsIndex {...list} hosted={[]} participating={[]} />,
        );

        expect(
            screen.getByText(/No project of yours is shared yet/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/You do not take part in anyone else/),
        ).toBeInTheDocument();
    });

    it('does not overflow with oversized names', () => {
        const { container } = render(
            <SharedProjectsIndex
                {...list}
                hosted={[{ ...list.hosted[0], name: LONG, client_name: LONG }]}
                participating={[
                    {
                        ...list.participating[0],
                        name: LONG,
                        host_workspace_name: LONG,
                    },
                ]}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

describe('Invitation', () => {
    const invitation: SharedInvitationProps = {
        invitation: {
            id: 'invitation-1',
            project_name: 'Synthetic Shared Project',
            host_workspace_name: 'Host Studio',
            email: 'invitee@example.test',
            expires_at: '2026-11-22T12:00:00Z',
        },
        workspaces: [
            { id: 'ws-1', name: 'First Workspace' },
            { id: 'ws-2', name: 'Second Workspace' },
        ],
        accept_url: '/shared-project-invitations/invitation-1/accept',
        decline_url: '/shared-project-invitations/invitation-1/decline',
        workspaces_url: '/app',
    };

    it('joins the workspace the person chooses, shown by name', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: true,
            status: 200,
            data: { redirect_url: '/workspaces/ws-2/shared-projects/p1' },
        });
        render(<SharedProjectInvitation {...invitation} />);

        expect(screen.getByText('First Workspace')).toBeInTheDocument();
        await user.click(
            screen.getByLabelText('Which of your workspaces joins'),
        );
        await user.click(
            await screen.findByRole('option', { name: 'Second Workspace' }),
        );
        await user.click(screen.getByRole('button', { name: 'Join' }));

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/shared-project-invitations/invitation-1/accept',
                { workspace_id: 'ws-2' },
            ),
        );
        expect(inertia.visit).toHaveBeenCalledWith(
            '/workspaces/ws-2/shared-projects/p1',
        );
    });

    it('declines without choosing a workspace', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: true,
            status: 200,
            data: { redirect_url: '/app' },
        });
        render(<SharedProjectInvitation {...invitation} />);

        await user.click(screen.getByRole('button', { name: 'Decline' }));

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/shared-project-invitations/invitation-1/decline',
                {},
            ),
        );
    });

    it('cannot be accepted by someone who administers no other workspace', () => {
        render(<SharedProjectInvitation {...invitation} workspaces={[]} />);

        expect(screen.getByRole('button', { name: 'Join' })).toBeDisabled();
        expect(
            screen.getByText(
                /Only an owner or admin of a workspace can accept/,
            ),
        ).toBeInTheDocument();
    });

    it('reports a refusal', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'That workspace already takes part in this project.',
            errors: {
                workspace: [
                    'That workspace already takes part in this project.',
                ],
            },
        });
        render(<SharedProjectInvitation {...invitation} />);

        await user.click(screen.getByRole('button', { name: 'Join' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'already takes part',
        );
        expect(inertia.visit).not.toHaveBeenCalled();
    });
});
