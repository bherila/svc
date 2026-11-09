import type { CompanyAgreement } from '@/types/clients';
import type { AttachmentSharing } from '@/types/files';

/**
 * One agreement, as both audiences receive it.
 *
 * The shape is the same for an operator and for the client, and the difference
 * between them is which keys are null — the portal sends the engine-behaviour
 * terms and the stored retainer columns as nulls rather than omitting them, so
 * the page renders one payload rather than branching on which controller
 * produced it.
 *
 * `CompanyAgreement` carries the *derived* commercial terms: the per-period
 * retainer figures, which are computed from either the monthly columns or the
 * per-cycle overrides. Those are the right thing to read and cannot be written
 * back, which is why the raw columns are here too and only the edit form uses
 * them.
 */
export type AgreementTerms = CompanyAgreement & {
    version: string;
    hourly_rate_amount: number | null;
    rollover_policy: string | null;
    catch_up_threshold_minutes: number | null;
    first_cycle_proration: string | null;
    bill_overage_interim: boolean | null;
    activated_at: string | null;
    terminated_at: string | null;
    signer_name: string | null;
    signer_title: string | null;
    /** The stored columns the operator's form edits. Null for a client. */
    retainer_minutes: number | null;
    retainer_amount: number | null;
    period_retainer_minutes: number | null;
    period_retainer_amount: number | null;
    agreement_text: string | null;
    is_visible_to_client: boolean | null;
    show_deferred_backlog_to_client?: boolean | null;
    /**
     * Billed in Square (#438): the Square series that bills this agreement,
     * or null. Operators only - absent on the client's own copy.
     */
    square_series_key?: string | null;
    /**
     * The Square location that series bills from (#488), or null for a
     * marker set before markers named one: it then matches the series in
     * every location. Operators only.
     */
    square_location_id?: string | null;
    /** Cancellation terms (CAN-1): the agreement's own copy, for both audiences. */
    notice_days: number;
    minimum_term_months: number | null;
    cancellation_mode: string;
};

/**
 * What this viewer may do to the agreement, as finished URLs.
 *
 * Null rather than a boolean, because the answer is a URL and the browser
 * should not be assembling one. Both are null for a client, and every endpoint
 * behind them authorizes again: a control nobody rendered is not a check.
 */
export type AgreementActions = {
    update: string | null;
    deferred_buydown?: { preview: string; create: string } | null;
    /** A service package the client signed that waits for an owner or admin (ACC-5): the endpoint and the version to send. */
    activate_enrollment?: { href: string; version: string } | null;
};

/** One file stored against the agreement. */
export type AgreementFile = {
    sharing?: AttachmentSharing | null;
    id: string;
    filename: string;
    media_type: string;
    bytes: number;
    uploaded_at: string | null;
    download_href: string;
    /** Absent for anyone who may read the file but not remove it. */
    delete_href: string | null;
};

/** One end date an owner or admin may choose, as the server offers it. */
export type CancellationOption = {
    value: string;
    /** Offered only once the notice period is waived. */
    waives_notice: boolean;
};

/**
 * The agreement page's cancellation panel for owners and admins (CAN-4,
 * EDGE-9): finished endpoints, the version each sends, and only the dates
 * the server offers. Null while the feature is off or for anyone else.
 */
export type OperatorCancellation = {
    earliest_end_on: string | null;
    pending: {
        requested_end_on: string;
        reason: string | null;
        requested_at: string;
        requested_by: string | null;
        version: string;
        options: CancellationOption[];
        approve_href: string;
        decline_href: string;
    } | null;
    scheduled: {
        ends_on: string;
        previous_ends_on: string | null;
        source: string;
        scheduled_at: string;
        version: string;
        undo_href: string;
    } | null;
    schedule: {
        version: string;
        options: CancellationOption[];
        href: string;
    } | null;
};

/**
 * The portal's cancellation panel (CAN-2, CAN-3, EDGE-9, EDGE-11): what the
 * client sees of ending an agreement. The URLs are present only for a
 * company-wide contact and only while the act is possible.
 */
export type PortalCancellation = {
    mode: string;
    notice_days: number;
    minimum_term_months: number | null;
    minimum_term_ends_on: string | null;
    earliest_end_on: string | null;
    offered_end_dates: string[];
    pending_request: {
        id: string;
        requested_end_on: string;
        reason: string | null;
        requested_at: string;
    } | null;
    scheduled_end: {
        id: string;
        ends_on: string;
        previous_ends_on: string | null;
        source: string;
        scheduled_at: string;
    } | null;
    provider_name: string;
    /** What the client owes now (EDGE-11): shown, never a reason to refuse. */
    balance: {
        amount_due: number | null;
        currency: string | null;
        invoice_count: number;
        overdue: boolean;
        overdue_amount: number | null;
    } | null;
    cancel_url: string | null;
    cancel_refusal: { code: string; message: string } | null;
    withdraw_url: string | null;
    undo_url: string | null;
    cancelled_by_client: boolean;
};
