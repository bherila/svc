export type PlanningLabel = {
    id: string;
    name: string;
    is_archived: boolean;
    version: string;
};

export type PlanningContact = {
    id: string;
    display_name: string;
    is_archived: boolean;
    version: string;
};

export type PlanningTaskReference = {
    id: string;
    title: string;
    status: string;
    version: string;
    web_url: string;
};

export type PlanningPage<T> = {
    data: T[];
    next_href: string | null;
    previous_href: null;
};

export type PlanningDependencyDetails = {
    task_id: string;
    version: string;
    graph_version: string;
    prerequisites: PlanningTaskReference[];
    dependents: PlanningTaskReference[];
};

export type TaskPlanningNativeDetails = {
    task_id: string;
    version: string;
    dependency_summary: {
        blocked: boolean;
        incomplete_prerequisite_count: number;
        dependent_count: number;
    };
    labels: {
        selected: PlanningLabel[];
        options: PlanningPage<PlanningLabel> | null;
        options_href: string | null;
        replace_href: string | null;
    };
    dependencies: {
        data: PlanningDependencyDetails | null;
        read_href: string;
        next_href: string | null;
        options: PlanningPage<PlanningTaskReference> | null;
        options_href: string | null;
        replace_href: string | null;
        unsupported_notice: string | null;
    };
    contacts?: {
        selected: PlanningContact[];
        version: string;
        options: PlanningPage<PlanningContact>;
        options_href: string;
        replace_href: string;
    };
    label_catalog_href: string | null;
};

export type PlanningCatalogLabel = PlanningLabel & {
    update_href: string | null;
    archive_href: string | null;
    restore_href: string | null;
};

export type TaskPlanningCatalogProps = {
    workspace: { id: string; name: string };
    catalog: {
        labels: PlanningCatalogLabel[];
        filters: { status: 'active' | 'archived' | 'all'; q: string };
        search_href: string;
        create_href: string;
        next_href: string | null;
        previous_href: null;
    };
};
