export type AttachmentSharing = {
    is_visible_to_client: boolean;
    effective_visible_to_client: boolean;
    sharing_note: string | null;
    version: string;
    parent_version: string;
    update_href: string;
};

export type SharedAttachment = {
    id: string;
    filename: string;
    media_type: string | null;
    bytes: number;
    uploaded_at: string | null;
    download_href: string;
    delete_href: string | null;
    sharing: AttachmentSharing | null;
    deletion?: { expected_version: string; expected_parent_version?: string } | null;
};

export type AttachmentPanel = {
    files: SharedAttachment[];
    upload: { href: string; parent_version: string } | null;
    next_href: string | null;
    parent_version?: string;
};
