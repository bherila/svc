export type TaskOrganizationTarget = {
    parent_id: string | null;
    list_id: string | null;
};

export type TaskOrganizationList = {
    id: string;
    name: string;
    version: string;
    is_visible_to_client: boolean;
    archived: boolean;
    browse_href: string;
    update_href: string | null;
    archive_href: string | null;
    restore_href: string | null;
    move_target: TaskOrganizationTarget | null;
};

export type TaskOrganizationRow = {
    id: string;
    title: string;
    status_label: string;
    version: string;
    is_visible_to_client: boolean;
    effective_visible_to_client: boolean;
    task_href: string;
    browse_href: string;
    create_child_href: string | null;
    child_target: TaskOrganizationTarget;
    move_picker_href: string;
    move_target: TaskOrganizationTarget | null;
};

export type TaskOrganizationView = {
    graph_version: string;
    lists_version: string;
    create_list_href: string | null;
    reorder_lists_href: string | null;
    ordered_list_ids: string[];
    assignees_href: string;
    unlisted: {
        browse_href: string;
        move_target: TaskOrganizationTarget | null;
    };
    lists: TaskOrganizationList[];
    container: {
        title: string;
        target: TaskOrganizationTarget;
        move_target: TaskOrganizationTarget | null;
        sharing_note: string | null;
        version: string;
        create_task_href: string | null;
        reorder_href: string | null;
        breadcrumbs: { label: string; href: string }[];
        tasks: TaskOrganizationRow[];
    };
    moving: {
        id: string;
        title: string;
        version: string;
        move_href: string;
        cancel_href: string;
    } | null;
};

export type TaskOrganizationPageProps = {
    company: { name: string; href: string };
    project: { id: string; name: string; href: string };
    organization: TaskOrganizationView;
};
