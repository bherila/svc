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
use App\Services\Billing\InvoiceLineComposer;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Time worked on the last day of a period belongs to that period.
 *
 * `worked_on` is a DATE column, but Eloquent's date casts write the model's
 * full date-time format. MariaDB truncates that to the date; SQLite, which the
 * local suite runs on, stores the text as given, so `2026-01-31 00:00:00` sorts
 * after `2026-01-31` and every `<=` or `whereBetween` bound ending on that day
 * left the entry out. A month-end bug could therefore pass the local suite.
 * The model now stores the bare date on every driver (#354).
 */
final class MonthEndTimeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_worked_on_is_stored_as_a_bare_date(): void
    {
        [$entry] = $this->fixture();

        foreach (['2026-01-31', Carbon::parse('2026-01-31 23:30:00'), CarbonImmutable::parse('2026-01-31')] as $value) {
            $entry->forceFill(['worked_on' => $value])->save();
            $this->assertSame('2026-01-31', DB::table('client_time_entries')->where('id', $entry->id)->value('worked_on'));
            $this->assertSame('2026-01-31', $entry->fresh()?->worked_on->toDateString());
        }

        $this->assertTrue(ClientTimeEntry::query()->whereKey($entry->id)->where('worked_on', '<=', '2026-01-31')->exists());
        $this->assertTrue(ClientTimeEntry::query()->whereKey($entry->id)->whereBetween('worked_on', ['2026-01-01', '2026-01-31'])->exists());
    }

    /** January's invoice bills the work done on January 31. */
    public function test_a_monthly_invoice_bills_the_last_day_of_its_month(): void
    {
        [$entry, $company, $agreement] = $this->fixture();
        $this->travelTo(Carbon::parse('2026-02-02 12:00:00'));

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $company, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), $agreement,
        );

        $this->assertTrue(
            $entry->fresh()?->invoiceLines()->where('client_invoice_id', $invoice->id)->exists(),
            'The month-end entry is billed on its month\'s invoice',
        );
    }

    /**
     * Both ends of a period whose bounds arrive as Carbon values, which bind
     * as `Y-m-d H:i:s`: the query compares dates, so the first day is not
     * lost where the last one used to be.
     */
    public function test_carbon_bounds_keep_the_first_and_last_day(): void
    {
        [$last, $company, $agreement] = $this->fixture();
        $first = $last->replicate(['public_id']);
        $first->forceFill(['worked_on' => '2026-01-01'])->save();
        foreach ([$first, $last] as $entry) {
            $entry->forceFill(['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD'])->save();
        }
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'invoice_number' => 'MONTH-END-1', 'status' => 'draft', 'currency' => 'USD',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $sort = 1;

        app(InvoiceLineComposer::class)->addSubcontractorFlatHourlyLines(
            $company, $invoice, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), $sort,
        );

        foreach ([$first, $last] as $entry) {
            $this->assertTrue(
                $entry->fresh()?->invoiceLines()->where('client_invoice_id', $invoice->id)->exists(),
                'Work on '.$entry->worked_on->toDateString().' is billed',
            );
        }
    }

    /** @return array{ClientTimeEntry, ClientCompany, ClientAgreement} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Month end', 'slug' => 'month-end']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Month end client', 'slug' => 'month-end-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Month end project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Hourly',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 0, 'retainer_amount' => 0, 'catch_up_threshold_minutes' => 0,
            'hourly_rate_amount' => 12000, 'billing_cadence' => 'monthly', 'rollover_months' => 0,
        ]);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id, 'worked_on' => '2026-01-31', 'minutes' => 60,
            'description' => 'Synthetic month-end work', 'is_billable' => true, 'status' => 'approved',
            'billing_rate_amount' => 12000, 'currency' => 'USD',
        ]);

        return [$entry, $company, $agreement];
    }
}
