export type CollaborationContent = {
    id: string;
    body: string | null;
    author_name: string;
    created_at: string | null;
    edited_at: string | null;
    deleted: boolean;
    version: string;
    href: string;
    is_visible_to_client: boolean | null;
    effective_visible_to_client: boolean | null;
    update_href: string | null;
    visibility_update_href: string | null;
    delete_href: string | null;
};

export type CollaborationTopic = CollaborationContent & {
    title: string;
    kind: 'note' | 'discussion';
    kind_label: string;
    project_id: string | null;
    scope_label: string;
};

export type CollaborationThread = {
    parent_kind: 'task' | 'note' | 'discussion';
    parent_title: string;
    parent_version: string;
    href: string;
    store_href: string | null;
    visibility_store_href: string | null;
    comments: CollaborationContent[];
    next_href: string | null;
    previous_href: string | null;
};

export type CollaborationIndexProps = {
    company: { id: string; name: string };
    audience: 'internal' | 'portal';
    topics: Pick<
        CollaborationTopic,
        'id' | 'title' | 'kind_label' | 'author_name' | 'href' | 'scope_label'
    >[];
    filters: { kind: string | null; project_id: string | null };
    filter_href: string;
    create_href: string | null;
    create_scopes: { id: string; label: string }[];
    next_href: string | null;
    previous_href: string | null;
};
