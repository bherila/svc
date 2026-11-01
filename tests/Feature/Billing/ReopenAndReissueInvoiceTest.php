<?php

namespace Tests\Feature\Billing;

use App\Mail\InvoiceMail;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceCorrection;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceEmailDraft;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

final class ReopenAndReissueInvoiceTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
        $this->workspace = $this->syntheticWorkspace('Reopen');
        $this->company = $this->syntheticCompany($this->workspace, 'Reopen');
        $this->project = $this->syntheticProject($this->company, 'Reopen');
        $this->member = $this->syntheticMember($this->workspace, 'Reopen worker');
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => 'monthly', 'rollover_months' => 1,
        ]);
    }

    public function test_an_issued_invoice_with_stale_application_lines_is_reopened_regenerated_and_reissued_under_its_number(): void
    {
        $first = $this->entry('2026-01-12', 510);
        $draft = $this->generate();
        $this->assertStringContainsString('8:30 applied', $this->applicationLine($draft));
        $issued = $this->lifecycle()->issue($draft, $this->workspace);
        $number = $issued->invoice_number;
        $this->assertSame(1, $issued->document_revision);
        $this->assertSame('invoiced', $first->fresh()?->status);

        // The October pattern: approvals that land after the invoice was built.
        $late = $this->entry('2026-01-20', 90);

        $reopened = $this->lifecycle()->reopen($issued, 'Late approvals missed the application lines', $this->workspace);

        $this->assertSame($issued->id, $reopened->id);
        $this->assertSame($number, $reopened->invoice_number);
        $this->assertSame('draft', $reopened->status);
        $this->assertNotNull($reopened->reopened_at);
        $this->assertSame('Late approvals missed the application lines', $reopened->reopen_reason);
        $this->assertFalse($reopened->is_visible_to_client);
        $this->assertNull($reopened->reissued_at);
        // Regenerated in the same step: the draft already describes the approved time...
        $this->assertStringContainsString('10:00 applied', $this->applicationLine($reopened));
        // ...which returned to approved and is consumed again by its lines.
        $this->assertSame('approved', $first->fresh()?->status);
        $this->assertSame('approved', $late->fresh()?->status);
        $this->assertEqualsCanonicalizing([$first->id, $late->id], $this->allocatedEntryIds($reopened));

        $reissued = $this->lifecycle()->issue($reopened, $this->workspace);

        $this->assertSame($issued->id, $reissued->id);
        $this->assertSame($number, $reissued->invoice_number);
        $this->assertSame('issued', $reissued->status);
        $this->assertSame(2, $reissued->document_revision, 'The revision moves on so the first issue\'s deliveries stay distinct');
        $this->assertNotNull($reissued->reissued_at);
        $this->assertNotNull($reissued->reopened_at, 'The reopen stays on the record');
        $this->assertTrue($reissued->is_visible_to_client);
        $this->assertStringContainsString('10:00 applied', $this->applicationLine($reissued));
        $this->assertSame('invoiced', $first->fresh()?->status);
        $this->assertSame('invoiced', $late->fresh()?->status);
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count(), 'No replacement invoice');
    }

    public function test_a_claimed_expense_stays_on_the_invoice_once_through_a_reopen_and_reissue(): void
    {
        $this->entry('2026-01-12', 510);
        $expense = $this->recordSyntheticExpense($this->workspace, $this->company, $this->project, $this->syntheticExpenseFacts(amount: 4_200, spentOn: '2026-01-20'));
        (new WorkspaceExpenses($this->workspace))->approve($expense, $this->member);
        $issued = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $this->assertSame(1, $issued->lines()->where('type', 'expense')->count());
        $total = $issued->total_amount;

        $reopened = $this->lifecycle()->reopen($issued, 'Stale application', $this->workspace);

        $expenseLines = $reopened->lines()->where('type', 'expense')->get();
        $this->assertCount(1, $expenseLines, 'The claimed expense is not billed beside a copy of itself');
        $this->assertSame($expenseLines->first()?->id, $expense->refresh()->client_invoice_line_id);
        $this->assertSame('invoiced', $expense->status);
        $this->assertSame($total, $reopened->total_amount);

        $reissued = $this->lifecycle()->issue($reopened, $this->workspace);
        $this->assertSame(1, $reissued->lines()->where('type', 'expense')->count());
        $this->assertSame($total, $reissued->total_amount);
    }

    public function test_the_activity_history_shows_issue_then_reopen_then_reissue(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $this->entry('2026-01-20', 90);
        $this->travelTo(Carbon::parse('2026-03-02 13:00:00'));
        $reopened = $this->lifecycle()->reopen($invoice, 'Stale application', $this->workspace);
        $this->travelTo(Carbon::parse('2026-03-02 14:00:00'));
        $this->lifecycle()->issue($reopened, $this->workspace);

        $history = ClientCompanyActivity::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('subject_public_id', $invoice->public_id)
            ->whereIn('action', ['invoice.issued', 'invoice.reopened', 'invoice.reissued', 'invoice.voided'])
            ->orderBy('id')
            ->get();

        $this->assertSame(['invoice.issued', 'invoice.reopened', 'invoice.reissued'], $history->pluck('action')->all());
        $this->assertSame('Stale application', $history[1]->payload['reason']);
        $this->assertSame('issued', $history[1]->payload['previous_status']);
    }

    public function test_a_second_reopen_and_reissue_is_recorded_too(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);

        $invoice = $this->lifecycle()->issue($this->lifecycle()->reopen($invoice, 'First', $this->workspace), $this->workspace);
        $invoice = $this->lifecycle()->issue($this->lifecycle()->reopen($invoice, 'Second', $this->workspace), $this->workspace);

        $this->assertSame(3, $invoice->document_revision);
        $this->assertSame('Second', $invoice->reopen_reason);
        $this->assertSame(
            ['invoice.issued', 'invoice.reopened', 'invoice.reissued', 'invoice.reopened', 'invoice.reissued'],
            ClientCompanyActivity::query()->where('subject_public_id', $invoice->public_id)->whereIn('action', ['invoice.issued', 'invoice.reopened', 'invoice.reissued'])->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_the_automatic_delivery_is_cancelled_on_reopen_as_a_void_cancels_it_and_scheduled_again_on_reissue(): void
    {
        $this->company->forceFill(['automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 3])->save();
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $this->assertSame('scheduled', $invoice->automatic_delivery_status);

        $reopened = $this->lifecycle()->reopen($invoice, 'Stale application', $this->workspace);

        $this->assertSame('cancelled', $reopened->automatic_delivery_status);
        $this->assertSame('Invoice reopened for reissue.', $reopened->automatic_delivery_note);

        $reissued = $this->lifecycle()->issue($reopened, $this->workspace);

        $this->assertSame('scheduled', $reissued->automatic_delivery_status);
        $this->assertNull($reissued->automatic_delivery_note, 'The reopen\'s note described the cancelled delivery');
        $this->assertNotNull($reissued->automatic_delivery_due_at);
    }

    public function test_a_delivery_row_and_the_client_documents_say_it_was_reissued(): void
    {
        Mail::fake();
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $email = app(InvoiceEmailService::class);
        $this->assertSame('Invoice '.$invoice->invoice_number, $email->defaultSubject($invoice));
        $original = $email->send($invoice, InvoiceEmailDraft::of(['billing@synthetic.example.test'], [], $email->defaultSubject($invoice), null), $this->workspace);
        $this->assertFalse($original->is_reissue);
        $this->assertSame('sent', $original->status);

        $reissued = $this->lifecycle()->issue($this->lifecycle()->reopen($invoice->fresh(), 'Stale application', $this->workspace), $this->workspace);

        $this->assertSame('Invoice '.$invoice->invoice_number.' (reissued)', $email->defaultSubject($reissued));
        $delivery = $email->send($reissued, InvoiceEmailDraft::of(['billing@synthetic.example.test'], [], $email->defaultSubject($reissued), null), $this->workspace);
        $this->assertTrue($delivery->is_reissue);
        $this->assertSame(2, $delivery->invoice_revision);
        $this->assertSame(2, ClientInvoiceEmailDelivery::query()->where('client_invoice_id', $invoice->id)->count(), 'The original delivery stays on the record');
        $html = (new InvoiceMail($reissued, null, null, 'pdf', 'invoice.pdf'))->render();
        $this->assertStringContainsString('reissued', $html);
        $this->assertStringContainsString('under the same invoice number', $html);
        $plain = $reissued->fresh();
        $plain?->setAttribute('reissued_at', null);
        $this->assertStringNotContainsString('reissued', (new InvoiceMail($plain, null, null, 'pdf', 'invoice.pdf'))->render());
    }

    public function test_a_paid_or_partly_paid_invoice_is_refused_with_voids_message(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $this->lifecycle()->applyPayment($invoice, ['amount' => 10000, 'currency' => 'USD', 'method' => 'ach', 'status' => 'succeeded', 'received_on' => '2026-03-02'], $this->workspace);

        $this->assertRefused($invoice->fresh(), 'A paid invoice cannot be reopened.');
        $this->lifecycle()->applyPayment($invoice, ['amount' => 140000, 'currency' => 'USD', 'method' => 'ach', 'status' => 'succeeded', 'received_on' => '2026-03-02'], $this->workspace);
        $this->assertSame('paid', $invoice->fresh()?->status);
        $this->assertRefused($invoice->fresh(), 'A paid invoice cannot be reopened.');
        // Void says the same thing about the same invoice.
        try {
            $this->lifecycle()->void($invoice->fresh(), $this->workspace);
            $this->fail('A paid invoice cannot be voided.');
        } catch (DomainException $refusal) {
            $this->assertSame('A paid invoice cannot be voided.', $refusal->getMessage());
        }
    }

    public function test_a_pending_payment_is_refused_with_voids_message(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $this->lifecycle()->applyPayment($invoice, [
            'amount' => 10000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_pending_reopen',
        ], $this->workspace);

        $this->assertRefused($invoice->fresh(), 'Cancel or resolve pending payments before reopening this invoice.');
    }

    public function test_a_payment_with_an_unreadable_status_is_refused_with_voids_message(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $this->lifecycle()->applyPayment($invoice, [
            'amount' => 10000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_unreadable_reopen',
        ], $this->workspace);
        DB::table('client_invoice_payments')->where('client_invoice_id', $invoice->id)->update(['status' => 'settled_somehow']);

        $this->assertRefused($invoice->fresh(), 'carries the unrecognised status "settled_somehow"');
        $this->assertRefused($invoice->fresh(), 'it must not be reopened. Classify or correct that payment status first.');
    }

    public function test_a_client_delivery_in_flight_is_refused_with_voids_message(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        foreach (['pending', 'sending'] as $status) {
            $delivery = $invoice->emailDeliveries()->create([
                'workspace_id' => $this->workspace->id, 'origin' => 'manual', 'invoice_revision' => 1, 'attempt_number' => 1,
                'recipients' => ['billing@synthetic.example.test'], 'subject' => 'Synthetic', 'status' => $status, 'queued_at' => now(),
            ]);

            $this->assertRefused($invoice->fresh(), 'Wait for the in-flight client delivery before reopening this invoice.');
            $delivery->delete();
        }
    }

    public function test_a_square_linked_invoice_is_refused_until_it_is_unlinked(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        InvoiceSquareLink::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id, 'square_invoice_id' => 'inv:0-reopen',
            'square_invoice_number' => '000009-R-0001', 'series_key' => '000009', 'currency' => 'USD', 'match_method' => 'manual',
            'active_invoice_slot' => $invoice->id, 'active_square_invoice_slot' => 'inv:0-reopen', 'linked_at' => now(),
        ]);

        $this->assertRefused($invoice->fresh(), 'invoices.square_unlink');

        InvoiceSquareLink::query()->where('client_invoice_id', $invoice->id)->update(['active_invoice_slot' => null, 'active_square_invoice_slot' => null]);
        $this->assertSame('draft', $this->lifecycle()->reopen($invoice->fresh(), 'Unlinked first', $this->workspace)->status);
    }

    public function test_an_invoice_on_an_agreement_billed_in_square_is_refused(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $this->agreement->forceFill(['square_series_key' => '000009'])->save();

        $this->assertRefused($invoice->fresh(), 'billed in Square');
    }

    public function test_a_corrected_invoice_is_refused_and_its_corrections_are_left_alone(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        ClientInvoiceCorrection::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id, 'from_revision' => 1, 'to_revision' => 2,
            'reason' => 'Synthetic wording fix', 'line_diffs' => [],
        ]);

        $this->assertRefused($invoice->fresh(), 'has been corrected in place');
        $this->assertSame(1, ClientInvoiceCorrection::query()->where('client_invoice_id', $invoice->id)->count());
    }

    public function test_only_an_issued_cadence_invoice_can_be_reopened(): void
    {
        $this->entry('2026-01-12', 510);
        $draft = $this->generate();
        $this->assertRefused($draft, 'Only an issued invoice can be reopened; invoice '.$draft->invoice_number.' is draft.');

        $issued = $this->lifecycle()->issue($draft, $this->workspace);
        $issued->forceFill(['invoice_kind' => 'ad_hoc'])->save();
        $this->assertRefused($issued->fresh(), 'Only an issued cadence invoice generated for an agreement can be reopened');
        $issued->forceFill(['invoice_kind' => 'cadence_period'])->save();

        $this->lifecycle()->void($issued->fresh(), $this->workspace, 'Synthetic');
        $this->assertRefused($issued->fresh(), 'is void.');
    }

    public function test_a_reason_is_required(): void
    {
        $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);

        $this->assertRefused($invoice->fresh(), 'A reason is required to reopen an invoice.', '   ');
        $this->assertRefused($invoice->fresh(), 'at most 1000 characters', str_repeat('x', 1001));
    }

    public function test_a_refused_reopen_changes_nothing(): void
    {
        $entry = $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $before = $this->snapshot($invoice);
        $invoice->forceFill(['invoice_kind' => 'ad_hoc'])->save();
        $before['kind'] = 'ad_hoc';

        $this->assertRefused($invoice->fresh(), 'Only an issued cadence invoice');

        $this->assertSame($before, $this->snapshot($invoice->fresh()) + ['kind' => 'ad_hoc']);
        $this->assertSame('invoiced', $entry->fresh()?->status);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.reopened')->count());
    }

    public function test_a_stale_version_check_runs_on_the_locked_row_before_anything_changes(): void
    {
        $entry = $this->entry('2026-01-12', 510);
        $invoice = $this->lifecycle()->issue($this->generate(), $this->workspace);

        try {
            $this->lifecycle()->reopen($invoice, 'Stale', $this->workspace, static fn () => throw new DomainException('Stale version.'));
            $this->fail('The caller\'s check must run.');
        } catch (DomainException $refusal) {
            $this->assertSame('Stale version.', $refusal->getMessage());
        }

        $this->assertSame('issued', $invoice->fresh()?->status);
        $this->assertSame('invoiced', $entry->fresh()?->status);
    }

    /**
     * Void is refused once a later invoice was sized against this one's charge.
     * Reopen keeps the invoice, so it is allowed while the regenerated charge
     * is the one the later invoice relied on - and refused, rolled back, when
     * it would not be.
     */
    public function test_a_later_invoice_sized_against_the_charge_allows_a_reopen_that_keeps_it_and_refuses_one_that_changes_it(): void
    {
        $this->entry('2026-01-12', 1200);
        $this->entry('2026-02-10', 600);
        $january = $this->lifecycle()->issue($this->generate(), $this->workspace);
        $this->assertSame(1.0, (float) $january->hours_billed_at_rate);
        $february = app(ClientInvoicingService::class)->generateInvoice($this->company, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'), $this->agreement);
        $this->lifecycle()->issue($february, $this->workspace);

        try {
            $this->lifecycle()->void($january->fresh(), $this->workspace);
            $this->fail('A void is refused while February relies on the charge.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('covers later work', $refusal->getMessage());
        }

        // Unchanged time: the same charge, so the reopen is allowed.
        $reopened = $this->lifecycle()->reopen($january->fresh(), 'Re-measure only', $this->workspace);
        $this->assertSame(1.0, (float) $reopened->hours_billed_at_rate);
        $reissued = $this->lifecycle()->issue($reopened, $this->workspace);
        $this->assertSame('issued', $reissued->status);

        // Time approved since would change the charge February was sized against.
        $late = $this->entry('2026-01-25', 600);
        $before = $this->snapshot($reissued);
        try {
            $this->lifecycle()->reopen($reissued->fresh(), 'Would change the charge', $this->workspace);
            $this->fail('February was sized against the old charge.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('regenerating this invoice', $refusal->getMessage());
            $this->assertStringContainsString($february->invoice_number, $refusal->getMessage());
        }
        $this->assertSame($before, $this->snapshot($reissued->fresh()), 'The refusal rolled the reopen back');
        $this->assertSame('approved', $late->fresh()?->status);
        $this->assertSame('invoiced', $this->entryStatuses($reissued)->first());
    }

    /** @return array<string, mixed> */
    private function snapshot(ClientInvoice $invoice): array
    {
        $invoice = $invoice->fresh(['lines']);
        $this->assertInstanceOf(ClientInvoice::class, $invoice);

        return [
            'status' => $invoice->status,
            'revision' => $invoice->document_revision,
            'total' => $invoice->total_amount,
            'reopened_at' => $invoice->reopened_at?->toISOString(),
            'lines' => $invoice->lines->map(fn ($line): array => [$line->id, $line->type, $line->description, $line->total_amount])->all(),
            'allocated' => collect($this->allocatedEntryIds($invoice))->sort()->values()->all(),
        ];
    }

    /** @return Collection<int, string> */
    private function entryStatuses(ClientInvoice $invoice): Collection
    {
        return ClientTimeEntry::query()->whereIn('id', $this->allocatedEntryIds($invoice))->pluck('status')->unique()->values();
    }

    /** @return list<int> */
    private function allocatedEntryIds(ClientInvoice $invoice): array
    {
        return DB::table('client_invoice_line_time_entries')
            ->whereIn('client_invoice_line_id', $invoice->lines()->pluck('id'))
            ->pluck('client_time_entry_id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function applicationLine(ClientInvoice $invoice): string
    {
        return (string) $invoice->lines()->where('type', 'prior_month_retainer')->value('description');
    }

    private function assertRefused(ClientInvoice $invoice, string $message, string $reason = 'Synthetic reason'): void
    {
        try {
            $this->lifecycle()->reopen($invoice, $reason, $this->workspace);
            $this->fail('The reopen must be refused: '.$message);
        } catch (DomainException $refusal) {
            $this->assertStringContainsString($message, $refusal->getMessage());
        }
    }

    private function lifecycle(): InvoiceLifecycleService
    {
        return app(InvoiceLifecycleService::class);
    }

    private function generate(): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice($this->company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), $this->agreement);
    }

    private function entry(string $workedOn, int $minutes, string $status = 'approved'): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->member->id,
            'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => 'Synthetic reopen work',
            'is_billable' => true, 'status' => $status, 'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);
    }
}
