<?php

namespace App\Support\Database\LocalStamps;

use Carbon\CarbonImmutable;

/**
 * One group of columns that code before #712 wrote with the workspace's wall
 * clock instead of UTC, and what is known about who else wrote them.
 *
 * `companion` is the UTC stamp Eloquent wrote in the same statement as the
 * local one: `updated_at` for an update, `created_at` for a create. A local
 * write leaves the two a zone offset apart, a UTC write leaves them equal.
 * `companionShiftDays` is how far ahead of that moment the column was set
 * (an invitation's `expires_at` is now plus fourteen days).
 *
 * `localSince` is the earliest moment a writer of the column could have
 * written local time (the switch's merge); `utcUntil` the latest one could
 * still have written UTC with `now()` (two hours after it: every one of these
 * deployed within ten minutes). A value stamped between the two edges -
 * shifted by the zone's offset - could be either, and is reported. Both are
 * null when the column was new with its local writer, so every value it holds
 * was local.
 *
 * `exclusive` says no writer wrote UTC into the column after `utcUntil`,
 * so a value inside the era is local even when its row has been updated
 * since and the companion no longer says so. A column with a UTC writer too
 * is corrected only on the companion's word.
 */
final readonly class LocalStampSpec
{
    /** How long after its merge a switch to the wall clock may not yet have been live. */
    public const DEPLOY_MARGIN_SECONDS = 7200;

    /** @param  non-empty-list<string>  $columns */
    public function __construct(
        public string $table,
        public string $tenant,
        public array $columns,
        public ?string $companion,
        public ?CarbonImmutable $localSince,
        public bool $exclusive,
        public bool $mayBeImported,
        public int $companionShiftDays = 0,
    ) {}

    /**
     * Every column written in workspace-local time before #712.
     *
     * The census behind each entry, and what was left out and why, is in
     * docs/client-management/timestamps.md.
     *
     * @return list<self>
     */
    public static function writtenLocalBefore712(): array
    {
        // #127 moved these writers from now() to the workspace clock.
        $workspaceClock = CarbonImmutable::parse('2026-08-30 06:29:02', 'UTC');
        // An expense's own approval.
        $expenseApproval = CarbonImmutable::parse('2026-09-04 18:39:20', 'UTC');
        // The access boundary took over portal project grants from a console
        // command that used now().
        $accessBoundary = CarbonImmutable::parse('2026-10-10 22:11:53', 'UTC');

        return [
            new self('client_agreements', 'workspace_id', ['activated_at', 'signed_at'], 'updated_at', $workspaceClock, true, true),
            new self('client_proposals', 'workspace_id', ['sent_at', 'accepted_at'], 'updated_at', $workspaceClock, true, true),
            new self('client_invoices', 'workspace_id', ['voided_at'], 'updated_at', $workspaceClock, true, true),
            new self('client_time_entries', 'workspace_id', ['approved_at'], 'updated_at', $workspaceClock, true, true),
            // Allocation soft-deletes a split fragment with deleted_at in UTC.
            new self('client_time_entries', 'workspace_id', ['deleted_at'], 'updated_at', $workspaceClock, false, true),
            new self('client_time_entries', 'workspace_id', ['client_approved_at', 'client_disputed_at', 'client_dispute_settled_at', 'submitted_at'], 'updated_at', null, true, true),
            new self('client_expenses', 'workspace_id', ['approved_at'], 'updated_at', $expenseApproval, true, true),
            new self('client_expenses', 'workspace_id', ['client_approved_at', 'client_disputed_at', 'client_dispute_settled_at', 'submitted_at'], 'updated_at', null, true, true),
            // The wall clock only stood in when the provider's event had no time.
            new self('client_invoice_email_deliveries', 'workspace_id', ['provider_status_at'], 'updated_at', $workspaceClock, false, false),
            new self('client_attachments', 'workspace_id', ['available_at', 'deleted_at'], 'updated_at', $workspaceClock, true, true),
            new self('reviewer_grants', 'granter_workspace_id', ['granted_at'], 'created_at', null, true, false),
            new self('reviewer_grants', 'granter_workspace_id', ['revoked_at'], 'updated_at', null, true, false),
            new self('client_project_participation_invitations', 'host_workspace_id', ['expires_at'], 'created_at', null, true, false, companionShiftDays: 14),
            new self('client_project_participation_invitations', 'host_workspace_id', ['accepted_at', 'declined_at', 'revoked_at'], 'updated_at', null, true, false),
            new self('client_project_participations', 'host_workspace_id', ['accepted_at'], 'created_at', null, true, false),
            new self('client_project_participations', 'host_workspace_id', ['revoked_at', 'left_at'], 'updated_at', null, true, false),
            new self('agent_mutation_receipts', 'workspace_id', ['completed_at'], 'updated_at', $workspaceClock, true, false),
            // The retired importer's runs, between #127 and its retirement.
            new self('external_import_runs', 'workspace_id', ['completed_at'], 'updated_at', $workspaceClock, false, false),
            new self('client_task_lists', 'workspace_id', ['deleted_at'], 'updated_at', null, true, false),
            // Written with DB::table, both stamps local: no companion. #111
            // recorded activity in UTC for the four hours before #127.
            new self('client_company_activity', 'workspace_id', ['created_at', 'updated_at'], null, $workspaceClock, true, true),
            new self('client_portal_project_access', 'workspace_id', ['created_at', 'updated_at'], null, $accessBoundary, true, false),
            new self('access_audit_events', 'workspace_id', ['created_at'], null, null, true, false),
        ];
    }

    /** The latest moment a writer of the column could still have written UTC. */
    public function utcUntil(): ?CarbonImmutable
    {
        return $this->localSince?->addSeconds(self::DEPLOY_MARGIN_SECONDS);
    }
}
