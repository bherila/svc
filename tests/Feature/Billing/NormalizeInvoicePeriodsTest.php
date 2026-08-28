<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A service period is the closed interval `[first, last]`.
 *
 * The predecessor did not always agree: for one run it started each period on
 * the previous invoice's end date, and the import copied that across. The
 * consequence is that `assertNoOverlappingInvoice()` sees a shared endpoint,
 * refuses every attempt to generate those months, and the invoice comes back
 * empty - which was the largest single cause of divergence in the replay.
 */
final class NormalizeInvoicePeriodsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Periods', 'slug' => 'periods']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Periods Client', 'slug' => 'periods-client',
        ]);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2024-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);
    }

    public function test_it_reports_without_writing_unless_apply_is_given(): void
    {
        $second = $this->invoice('2024-01-31', '2024-02-29');
        $this->invoice('2024-01-01', '2024-01-31');

        $this->artisan('svc:billing:normalize-invoice-periods', [
            '--workspace' => $this->workspace->public_id,
        ])->assertSuccessful();

        $this->assertSame('2024-01-31', $second->refresh()->service_period_start->toDateString());
    }

    public function test_it_advances_a_period_that_starts_where_another_ended(): void
    {
        $second = $this->invoice('2024-01-31', '2024-02-29');
        $first = $this->invoice('2024-01-01', '2024-01-31');

        $this->artisan('svc:billing:normalize-invoice-periods', [
            '--workspace' => $this->workspace->public_id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('2024-02-01', $second->refresh()->service_period_start->toDateString());
        $this->assertSame('2024-01-01', $first->refresh()->service_period_start->toDateString(), 'The earlier invoice is unchanged');
    }

    public function test_a_period_that_already_begins_after_the_last_one_is_left_alone(): void
    {
        $second = $this->invoice('2024-02-01', '2024-02-29');
        $this->invoice('2024-01-01', '2024-01-31');

        $this->artisan('svc:billing:normalize-invoice-periods', [
            '--workspace' => $this->workspace->public_id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('2024-02-01', $second->refresh()->service_period_start->toDateString());
    }

    /**
     * The defect is historical, so almost every affected invoice is settled.
     * Moving a period changes no money, but rewriting a settled invoice is
     * refused everywhere else here, so it is surfaced rather than done quietly.
     */
    public function test_a_settled_invoice_is_left_alone_unless_asked_for(): void
    {
        $second = $this->invoice('2024-01-31', '2024-02-29');
        $second->forceFill(['status' => 'paid'])->save();
        $this->invoice('2024-01-01', '2024-01-31');

        $this->artisan('svc:billing:normalize-invoice-periods', [
            '--workspace' => $this->workspace->public_id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('2024-01-31', $second->refresh()->service_period_start->toDateString());

        $this->artisan('svc:billing:normalize-invoice-periods', [
            '--workspace' => $this->workspace->public_id,
            '--apply' => true,
            '--include-settled' => true,
        ])->assertSuccessful();

        $this->assertSame('2024-02-01', $second->refresh()->service_period_start->toDateString());
    }

    /**
     * Two agreements for one client bill independently, so their periods may
     * legitimately abut - the overlap guard scopes to the agreement too.
     */
    public function test_periods_under_a_different_agreement_may_abut(): void
    {
        $other = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Second', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2024-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);

        $second = $this->invoice('2024-01-31', '2024-02-29', $other);
        $this->invoice('2024-01-01', '2024-01-31');

        $this->artisan('svc:billing:normalize-invoice-periods', [
            '--workspace' => $this->workspace->public_id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('2024-01-31', $second->refresh()->service_period_start->toDateString());
    }

    private function invoice(string $start, string $end, ?ClientAgreement $agreement = null): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => ($agreement ?? $this->agreement)->id,
            'invoice_number' => 'P-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'service_period_start' => $start,
            'service_period_end' => $end,
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
    }
}
