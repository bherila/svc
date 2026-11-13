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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The final reconciliation of a terminated agreement collects the deferred
 * work still waiting at the hourly rate. The invoice charges it, so the
 * statement on the same document must not also carry those hours forward as a
 * deficit the client still owes - on either cadence path.
 */
final class TerminationDeficitStatementTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function cadences(): iterable
    {
        yield 'monthly' => [['billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000], '2026-03-01'];
        yield 'quarterly' => [['billing_cadence' => 'quarterly', 'period_retainer_minutes' => 600, 'period_retainer_amount' => 100000], '2026-01-01'];
    }

    /** @param array<string, mixed> $terms */
    #[DataProvider('cadences')]
    public function test_the_final_statement_owes_none_of_the_deferred_hours_its_termination_charge_collected(array $terms, string $workFrom): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic termination deficit', 'slug' => 'synthetic-termination-deficit']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic termination client', 'slug' => 'synthetic-termination-client', 'billing_email' => 'ap-termination@synthetic.example.test']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic termination project']);
        ClientAgreement::query()->create($terms + [
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic terminated retainer', 'status' => 'active',
            'starts_on' => $workFrom, 'ends_on' => '2026-03-31', 'currency' => 'USD', 'hourly_rate_amount' => 10000, 'rollover_months' => 0,
        ]);
        $entry = fn (string $day, int $minutes, bool $deferred): ClientTimeEntry => ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $owner->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => $deferred, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ]);
        // The period's work uses its whole retainer, so no spare capacity
        // takes the deferred hours: termination collects them at the rate.
        $entry('2026-03-10', 600, false);
        $entry('2026-03-12', 300, true);

        Carbon::setTestNow(Carbon::parse('2026-04-05'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($company);
        } finally {
            Carbon::setTestNow();
        }

        $final = ClientInvoice::query()->where('workspace_id', $workspace->id)->get()
            ->filter(fn (ClientInvoice $invoice): bool => ($invoice->hoursStatement()->deferredBilledOnTerminationHours ?? 0.0) > 0.0)
            ->sole();
        $collected = (float) $final->lines()->where('description', 'like', 'Deferred work items billed on agreement termination%')->sum('hours');
        $this->assertSame(5.0, $collected, 'The termination charge collects the waiting deferred hours.');
        $statement = $final->hoursStatement();
        $this->assertNotNull($statement);
        $this->assertSame(5.0, $statement->deferredBilledOnTerminationHours);
        $this->assertSame(0.0, $statement->deficitCarriedForwardHours, 'Nothing the charge collected is carried forward as owed.');
        $this->assertSame(0.0, (float) $final->negative_hours_balance);
    }
}
