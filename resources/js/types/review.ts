import type { KeyedApprovalResult, PortalToApprove } from '@/types/portal';

/**
 * What the cross-workspace "To approve" is sent (docs/ux-overhaul.md sections
 * 7c and 7d).
 *
 * One block for each provider that lets this person decide, either through a
 * portal membership or through a reviewer grant. The reviewer's blocks carry no
 * invoice, rate or internal note, and every URL arrives finished.
 */
export type ApprovalBlock = {
    /** The client's id for a portal block, the grant's for a reviewer block. */
    key: string;
    source: 'portal' | 'grant';
    /** The provider workspace, whose rows these are. */
    provider: string;
    /** The provider's name for the client the hours are for. */
    client: string;
    pending: number;
    to_approve: PortalToApprove;
};

export type ApprovalsProps = {
    blocks: ApprovalBlock[];
    approval_result: KeyedApprovalResult | null;
    workspaces_url: string;
};

export type ReviewerGrantClient = {
    id: string;
    name: string;
    projects: { id: string; name: string }[];
    contacts: { id: string; name: string; email: string }[];
};

export type ReviewerGrantRow = {
    id: string;
    client: string;
    /** Null: the whole client. */
    project: string | null;
    kind: 'contact' | 'workspace';
    grantee: string;
    /** The address a contact grant is written to. */
    detail: string | null;
    granted_at: string;
    revoked_at: string | null;
    /** Null once revoked. */
    revoke_url: string | null;
};

export type ReviewerGrantsProps = {
    workspaceName: string;
    store_url: string;
    clients: ReviewerGrantClient[];
    workspaces: { id: string; name: string }[];
    grants: ReviewerGrantRow[];
};
