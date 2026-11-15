<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\PricingBasisChanged;
use App\Support\Billing\PricingBasisMissing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A cadence draft is issued only while every ledger input it was priced from
 * is as it was (#718, review finding B4). Its figures come from the
 * agreement's ledger, and a stale draft otherwise charges the ledger as it
 * was - re-carried deferred hours on a final invoice, say, which link no time
 * and so nothing else rechecks.
 */
final class PricingBasisTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic pricing basis', 'slug' => 'synthetic-pricing-basis']);
        $this->user = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic basis client', 'slug' => 'synthetic-basis-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic basis project']);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic monthly retainer', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
            'retainer_minutes' => 600, 'retainer_amount' => 60000, 'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
    }

    public function test_a_draft_whose_priced_time_has_moved_is_not_issued(): void
    {
        $entry = $this->entry('2026-01-10', 900);
        $february = $this->generate('2026-02-01');
        $this->assertSame(15.0, $this->linkedHours($february));

        // The draft split the fifteen into the retainer's ten and five over.
        // Cut the ten to five after it was priced, as a time edit commits it.
        $this->assertSame(600, $entry->fresh()->minutes);
        ClientTimeEntry::query()->whereKey($entry->id)->update(['minutes' => 300, 'lock_version' => DB::raw('lock_version + 1')]);

        try {
            $this->issue($february);
            $this->fail('A draft priced from fifteen hours was issued against ten.');
        } catch (PricingBasisChanged) {
        }
        $this->assertSame('draft', $february->fresh()->status);

        // Regenerated, it prices the ten and issues.
        $february = $this->generate('2026-02-01');
        $this->assertSame(10.0, $this->linkedHours($february));
        $this->assertSame('issued', $this->issue($february)->status);
    }

    /**
     * Work logged after the draft was priced is later work: a later invoice
     * bills it, and it does not make this draft's charges wrong.
     */
    public function test_an_hour_logged_after_the_draft_was_priced_does_not_refuse_it(): void
    {
        $this->entry('2026-01-10', 600);
        $february = $this->generate('2026-02-01');
        $this->entry('2026-01-20', 300);

        $this->assertSame('issued', $this->issue($february)->status);
    }

    public function test_an_edit_that_cannot_change_money_does_not_refuse_the_draft(): void
    {
        $entry = $this->entry('2026-01-10', 600);
        $february = $this->generate('2026-02-01');

        ClientTimeEntry::query()->whereKey($entry->id)->update(['description' => 'Synthetic reworded', 'lock_version' => DB::raw('lock_version + 1')]);

        $this->assertSame('issued', $this->issue($february)->status);
    }

    public function test_an_earlier_invoice_being_paid_does_not_refuse_the_next_draft(): void
    {
        $this->entry('2026-01-10', 600);
        $february = $this->issue($this->generate('2026-02-01'));
        $this->entry('2026-02-10', 600);
        $march = $this->generate('2026-03-01');

        ClientInvoice::query()->whereKey($february->id)->update(['status' => 'paid']);

        $this->assertSame('issued', $this->issue($march)->status);
    }

    public function test_a_draft_generated_before_its_basis_was_recorded_is_regenerated_first(): void
    {
        $this->entry('2026-01-10', 600);
        $february = $this->generate('2026-02-01');
        ClientInvoice::query()->whereKey($february->id)->update(['pricing_basis' => null]);

        try {
            $this->issue($february);
            $this->fail('A draft with no recorded basis was issued.');
        } catch (PricingBasisMissing) {
        }

        $this->assertSame('issued', $this->issue($this->generate('2026-02-01'))->status);
    }

    private function entry(string $workedOn, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'user_id' => $this->user->id, 'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ]);
    }

    private function generate(string $periodStart): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateCadencePeriod($this->company->fresh(), $this->agreement->fresh(), Carbon::parse($periodStart));
    }

    private function issue(ClientInvoice $draft): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->issue($draft->fresh(), $this->workspace);
    }

    private function linkedHours(ClientInvoice $invoice): float
    {
        return round((float) $invoice->lines()->whereHas('timeEntries')->sum('hours'), 4);
    }
}
