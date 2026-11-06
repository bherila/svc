/**
 * What a shared project page is sent (docs/ux-overhaul.md section 7a).
 *
 * Every URL arrives finished and a null is an action this viewer may not take,
 * so the page never builds a path or interprets a role. Money is not here and
 * cannot be: the server never selects a rate, time entry, invoice or note of
 * the host or another participant for a participant's view.
 */

export type SharedAssignee = {
    name: string;
    /** The workspace the person works for, shown beside their name. */
    workspace_name: string;
    /** Only told to a viewer who may assign. */
    user_id?: string;
    workspace_id?: string;
};

export type AssigneeOption = {
    user_id: string;
    name: string;
    workspace_id: string;
    workspace_name: string;
};

export type SharedTask = {
    id: string;
    title: string;
    description: string | null;
    status: string;
    shared_with_participants: boolean;
    starts_on: string | null;
    due_on: string | null;
    completed_at: string | null;
    assignees: SharedAssignee[];
    can_comment: boolean;
    comments_url: string;
    /** Null unless this viewer may hand the task to people. */
    assign_url: string | null;
};

export type SharedComment = {
    id: string;
    body: string;
    author_name: string;
    author_workspace_name: string;
    shared_with_participants: boolean;
    created_at: string | null;
};

export type SharedParticipant = {
    id: string;
    workspace_name: string;
    accepted_at: string;
    revoke_url: string | null;
};

export type SharedInvitation = {
    id: string;
    email: string;
    expires_at: string;
    accept_url: string;
    revoke_url: string;
};

export type SharedProject = {
    id: string;
    name: string;
    status: string;
    side: 'host' | 'participant';
    host_workspace_name: string;
    client_name?: string | null;
    description?: string | null;
    viewer: {
        sees_whole_project: boolean;
        can_assign: boolean;
        can_manage_participants: boolean;
        can_act_as_participant: boolean;
    };
    participants?: SharedParticipant[];
    invitations?: SharedInvitation[];
    participation?: { accepted_at: string };
    /** Only for a participant's owners and admins. */
    local_project?: { id: string; name: string } | null;
    assignee_options?: AssigneeOption[];
};

export type SharedProjectShowProps = {
    workspaceName: string;
    project: SharedProject;
    tasks: SharedTask[];
    tasks_next_cursor: string | null;
    tasks_url: string;
    urls: {
        index: string;
        invite: string | null;
        leave: string | null;
        link: string | null;
        host_project: string | null;
    };
    local_project_options: { id: string; name: string }[];
};

export type SharedProjectIndexProps = {
    workspaceName: string;
    hosted: {
        id: string;
        name: string;
        client_name: string;
        web_url: string;
    }[];
    participating: {
        id: string;
        name: string;
        host_workspace_name: string;
        web_url: string;
    }[];
};

export type SharedInvitationProps = {
    invitation: {
        id: string;
        project_name: string;
        host_workspace_name: string;
        email: string;
        expires_at: string;
    };
    workspaces: { id: string; name: string }[];
    accept_url: string;
    decline_url: string;
    workspaces_url: string;
};
