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
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The retainer lent to an overflow is what the ledger has not already spent.
 *
 * 10 retainer hours a month. January ran 25 hours and its (issued) invoice
 * charged none of the excess, so 15 hours of debt reach February, whose
 * retainer pays 10 and leaves 5 owing. February then runs 8 hours.
 *
 * The ledger spends March's retainer on the 5 still owed before anything else.
 * The March invoice lent the whole 10 to February's overflow, so its lines
 * said 2 hours were left while its own opening balance recorded 3 owed. With
 * only 5 lent, 3 hours are uncovered and the minimum-availability rule restores
 * the one-hour buffer: 4 hours at rate, and March opens with exactly 1 hour.
 */
final class CarriedDeficitCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_next_retainer_is_lent_only_net_of_debt_it_must_repay_first(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Deficit', 'slug' => 'deficit']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Ember Studio', 'slug' => 'ember-studio']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Studio']);
        $user = User::factory()->create();
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => 'monthly', 'rollover_months' => 1,
        ]);
        $entry = fn (string $workedOn, int $minutes): ClientTimeEntry => ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $user->id, 'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => 'Work',
            'is_billable' => true, 'is_deferred' => false, 'status' => 'approved',
            'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);

        $february = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'invoice_number' => 'EMBE-202602-001', 'status' => 'issued', 'invoice_kind' => InvoiceKind::CadencePeriod->value,
            'issue_date' => '2026-02-01', 'service_period_start' => '2026-01-01', 'service_period_end' => '2026-01-31',
            'cycle_start' => '2026-02-01', 'cycle_end' => '2026-02-28', 'currency' => 'USD',
            'subtotal_amount' => 150000, 'tax_amount' => 0, 'total_amount' => 150000, 'balance_amount' => 150000,
            'hours_billed_at_rate' => 0,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $february->id, 'client_agreement_id' => $agreement->id,
            'type' => InvoiceLineType::PriorMonthRetainer->value, 'description' => 'Work items applied to retainer',
            'quantity' => '0', 'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'hours' => 25,
            'line_date' => '2026-01-31', 'sort_order' => 1,
        ]);
        $line->timeEntries()->attach($entry('2026-01-15', 1500)->id, ['workspace_id' => $workspace->id]);
        $entry('2026-02-10', 480);

        $march = app(ClientInvoicingService::class)->generateInvoice(
            $company, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'), $agreement,
        );

        $descriptions = $march->lines->pluck('description')->all();
        $this->assertContains('Work items applied to retainer (5:00 applied to March 2026 pool)', $descriptions);
        $catchUp = $march->lines->firstWhere('type', InvoiceLineType::AdditionalHours->value);
        $this->assertNotNull($catchUp);
        $this->assertSame(4.0, (float) $catchUp->hours);
        $this->assertSame(60000, (int) $catchUp->total_amount);
        // What the lines claim and what the invoice records now agree.
        $this->assertSame(1.0, (float) $march->starting_unused_hours);
        $this->assertSame(0.0, (float) $march->starting_negative_hours);
    }
}
