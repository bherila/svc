<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\DeferredBillingAllocator;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\OverpaymentCreditService;
use App\Services\Billing\TimeEntrySplitter;
use App\Services\WorkspaceAuthorization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * Boundaries the billing engine must hold that its own arithmetic cannot check.
 *
 * The engine was ported from a single-tenant system, so nothing in the
 * arithmetic knows a workspace exists. Company ids happen to be unique today,
 * which makes company-only scoping look sufficient right up until a malformed
 * or migrated reference makes it not - and the failure mode is one tenant's
 * work billed to another.
 */
final class BillingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_deferred_allocation_ignores_another_workspaces_entries(): void
    {
        [$company, $project, $user] = $this->tenant('alpha');
        [, $otherProject, $otherUser] = $this->tenant('beta');

        $this->deferredEntry($company->workspace_id, $company->id, $project->id, $user->id, 60);

        // The same company id, reachable only if a reference is malformed - but
        // the point is that the query must not depend on that never happening.
        // The composite tenant keys refuse to store it now, so enforcement is
        // suspended to reproduce a row a pre-#113 database can still hold.
        $this->writingLegacyCrossTenantRows(
            fn () => $this->deferredEntry($otherProject->workspace_id, $company->id, $otherProject->id, $otherUser->id, 600),
        );

        $allocator = new DeferredBillingAllocator;

        $termination = $allocator->collectForTermination($company, Carbon::parse('2024-06-30'));
        $this->assertSame(1, $termination->count(), 'Only this workspace\'s deferred work may be collected');
        $this->assertSame(60, (int) $termination->sum('minutes'));

        $allocated = $allocator->allocate($company, Carbon::parse('2024-06-30'), 100.0);
        $this->assertSame(1.0, $allocated->hoursBilled, 'The other tenant\'s 10h must not be drawn on this retainer');
    }

    /**
     * Issuing an invoice rewrites its time to `invoiced`. If the ledger only
     * accepted the literal `approved`, every rebuild after the first issue would
     * forget the work it had already billed - inflating rollover and
     * understating the next overage.
     */
    public function test_the_ledger_still_sees_work_after_its_invoice_is_issued(): void
    {
        [$company, $project, $user] = $this->tenant('gamma');
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 2,
        ]);

        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'worked_on' => '2024-01-15',
            'minutes' => 240,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        $builder = new InvoiceLedgerBuilder;
        $before = $builder->buildAgreementLedgerThrough($company, $agreement, Carbon::parse('2024-01-31'), false);
        $hoursBefore = array_sum(array_map(static fn ($m): float => $m->hoursWorked, $before));
        $this->assertSame(4.0, $hoursBefore);

        $entry->forceFill(['status' => 'invoiced'])->save();

        $after = $builder->buildAgreementLedgerThrough($company, $agreement, Carbon::parse('2024-01-31'), false);
        $hoursAfter = array_sum(array_map(static fn ($m): float => $m->hoursWorked, $after));
        $this->assertSame($hoursBefore, $hoursAfter, 'Invoiced work is history, not absence');
    }

    /**
     * A split fragment must carry the provenance of the rate it inherited, or a
     * derived rate becomes indistinguishable from one an operator typed.
     */
    public function test_a_split_fragment_keeps_its_rate_provenance(): void
    {
        [$company, $project, $user] = $this->tenant('delta');

        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'worked_on' => '2024-01-15',
            'minutes' => 120,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
            'billing_rate_amount' => 20000,
            'billing_rate_source' => 'agreement',
        ]);

        $this->assertSame('agreement', $entry->refresh()->billing_rate_source, 'The field must survive a create');

        $split = app(TimeEntrySplitter::class)->splitEntry($entry, 60);

        $this->assertSame('agreement', $split['overflow']->refresh()->billing_rate_source);
        $this->assertSame(20000, (int) $split['overflow']->billing_rate_amount);
    }

    /**
     * Two drafts may each be offered the whole credit pool - drafts regenerate
     * freely and reserving against them would strand the money. What must not
     * happen is both being issued and both spending it.
     */
    public function test_two_drafts_cannot_both_spend_the_same_credit(): void
    {
        [$company] = $this->tenant('epsilon');

        // An invoice paid 100.00 over.
        $settled = ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'invoice_number' => 'SVC-90001',
            'currency' => 'USD',
            'status' => 'paid',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 20000,
        ]);
        $settled->payments()->create([
            'workspace_id' => $company->workspace_id,
            'amount' => 20000,
            'refunded_amount' => 0,
            'currency' => 'USD',
            'status' => 'succeeded',
            'method' => 'manual',
            'received_on' => '2024-01-31',
        ]);

        $credits = new OverpaymentCreditService;
        $this->assertSame(100.0, $credits->availableCreditForCompany($company, 'USD'));

        $first = $this->draftWorth($company, 'SVC-90002', 50000);
        $second = $this->draftWorth($company, 'SVC-90003', 50000);

        // Both drafts legitimately see the full pool.
        $credits->applyCreditsToDraftInvoice($first);
        $credits->applyCreditsToDraftInvoice($second);
        $this->assertSame(-10000, (int) $first->refresh()->lines()->where('type', 'credit')->value('total_amount'));
        $this->assertSame(-10000, (int) $second->refresh()->lines()->where('type', 'credit')->value('total_amount'));

        $lifecycle = new InvoiceLifecycleService(
            app(WorkspaceAuthorization::class),
            app(ClientActivityRecorder::class),
        );

        $lifecycle->issue($first->refresh());
        $this->assertSame(40000, (int) $first->refresh()->total_amount, 'The first issue spends the credit');

        $lifecycle->issue($second->refresh());
        $this->assertSame(
            50000,
            (int) $second->refresh()->total_amount,
            'The pool was already spent, so the second invoice carries no credit',
        );
        $this->assertSame(0, $second->refresh()->lines()->where('type', 'credit')->count());
    }

    private function draftWorth(ClientCompany $company, string $number, int $amount): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'currency' => 'USD',
            'status' => 'draft',
            // Said rather than left null, which reads as `cadence_period` and
            // may not be issued without a service period (#251). These are two
            // bare drafts against a company with no schedule and no agreement,
            // which is exactly the shape `createDraft()` classifies as ad hoc -
            // and keeping them exempt keeps this test about the credit pool
            // rather than about the period guard.
            'invoice_kind' => 'ad_hoc',
            'subtotal_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_invoice_id' => $invoice->id,
            'type' => 'additional_hours',
            'description' => 'Work',
            'quantity' => '1',
            'unit_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'sort_order' => 0,
        ]);

        return $invoice;
    }

    /** @return array{ClientCompany, ClientProject, User} */
    private function tenant(string $slug): array
    {
        $workspace = Workspace::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => ucfirst($slug).' Client', 'slug' => $slug.'-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => ucfirst($slug).' Project',
        ]);

        return [$company, $project, User::factory()->create()];
    }

    private function deferredEntry(int $workspaceId, int $companyId, int $projectId, int $userId, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspaceId,
            'client_company_id' => $companyId,
            'client_project_id' => $projectId,
            'user_id' => $userId,
            'worked_on' => '2024-06-10',
            'minutes' => $minutes,
            'description' => 'Deferred work',
            'is_billable' => true,
            'is_deferred' => true,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
