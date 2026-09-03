import type { CompanyAgreement } from '@/types/clients';

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
    upload_file: string | null;
};

/** One file stored against the agreement. */
export type AgreementFile = {
    id: string;
    filename: string;
    media_type: string;
    bytes: number;
    uploaded_at: string | null;
    download_href: string;
    /** Absent for anyone who may read the file but not remove it. */
    delete_href: string | null;
};
