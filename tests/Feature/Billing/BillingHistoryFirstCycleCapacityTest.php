<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\FirstCycleProration;
use App\Support\Billing\InvoiceLineType;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\SignsUpThroughPackages;
use Tests\Concerns\WalksBillingHistories;
use Tests\TestCase;

/** Legacy monthly accrual and native period terms keep their distinct opening entitlements (#703). */
final class BillingHistoryFirstCycleCapacityTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;
    use SignsUpThroughPackages;
    use WalksBillingHistories;

    /** @return iterable<string, array{bool, string, float, int, array<string, float>}> */
    public static function openingQuarters(): iterable
    {
        foreach ([false, true] as $package) {
            foreach (FirstCycleProration::cases() as $proration) {
                [$hours, $fee, $openingGrant] = $package ? [30.0, 90000, 30.0] : match ($proration) {
                    FirstCycleProration::ProrateHours, FirstCycleProration::AlignNextCycle => [35.161, 105483, 5.161],
                    FirstCycleProration::FullPeriod => [40.0, 120000, 10.0],
                };
                $buckets = ['2026-01' => $openingGrant, '2026-02' => $package ? 0.0 : 10.0, '2026-03' => $package ? 0.0 : 10.0, '2026-04' => $package ? 0.0 : 10.0];

                yield ($package ? 'package period terms' : 'standalone monthly terms').', '.$proration->value => [$package, $proration->value, $hours, $fee, $buckets];
            }
        }
    }

    /** @param array<string, float> $buckets */
    #[DataProvider('openingQuarters')]
    public function test_monthly_accrual_and_period_terms_preserve_their_distinct_opening_capacity(bool $package, string $proration, float $hours, int $fee, array $buckets): void
    {
        [$workspace, $company, $agreement] = $package
            ? $this->signUpOn(16, [
                'billing_cadence' => 'quarterly', 'invoice_issuing' => 'draft', 'activation_mode' => 'automatic', 'notice_days' => 0,
                'retainer_amount' => 90000, 'retainer_minutes' => 1800, 'hourly_rate_amount' => 10000,
                'first_cycle_proration' => $proration, 'components' => [],
            ])
            : $this->standalone($proration);

        $first = $this->generateAndAssertPeriod($workspace, $company, $agreement, '2026-01-16', '2026-04-15', $hours, $fee, $buckets);
        app(InvoiceLifecycleService::class)->issue($first, $workspace);
        $this->travelTo(CarbonImmutable::parse('2026-04-16 12:00:00', 'UTC'));
        $next = $this->generateAndAssertPeriod($workspace, $company, $agreement, '2026-04-16', '2026-07-15', 30.0, 90000);
        $this->assertSame($hours, $next->hoursStatement()?->openingRetainerHours, 'The next reconciliation uses the capacity its preceding invoice actually sold.');
    }

    /** @param array<string, float>|null $expectedBuckets */
    private function generateAndAssertPeriod(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement, string $startsOn, string $endsOn, float $hours, int $fee, ?array $expectedBuckets = null): ClientInvoice
    {
        $invoice = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse($startsOn));
        $line = $invoice->lines()->where('workspace_id', $workspace->id)->where('type', InvoiceLineType::Retainer->value)->sole();
        $this->assertSame([$startsOn, $endsOn], [$invoice->cycle_start?->toDateString(), $invoice->cycle_end?->toDateString()]);
        $this->assertSame(number_format($hours, 4, '.', ''), $line->hours);
        $this->assertSame($fee, (int) $line->total_amount, 'The fee follows the same monthly grants or native period entitlement as the hours.');
        $this->assertSame($fee, (int) $invoice->total_amount);
        $this->assertSame($hours, $invoice->hoursStatement()?->nextRetainerHours);

        $cycle = app(BillingCycleResolver::class)->cycleContaining($agreement, Carbon::parse($startsOn));
        $ledger = app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough($company, $agreement, Carbon::parse($endsOn));
        $summary = app(InvoiceLedgerBuilder::class)->summarizeLedgerForCycle($agreement, $ledger, $cycle);
        $this->assertSame($hours, $summary['retainer_hours'], 'The ledger grants exactly the capacity the invoice sells.');
        if ($expectedBuckets !== null) {
            $actualBuckets = [];
            foreach (app(InvoiceLedgerBuilder::class)->cycleSummaries($agreement, $ledger, $cycle) as $month) {
                $actualBuckets[$month->yearMonth] = $month->retainerHours;
            }
            $this->assertSame($expectedBuckets, $actualBuckets, 'Legacy rows accrue in calendar months; a native period has one opening pool.');
        }

        return $invoice;
    }

    /** @return array{Workspace, ClientCompany, ClientAgreement} */
    private function standalone(string $proration): array
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-01-16 10:00:00', 'UTC'));
        [$workspace] = $this->packageWorkspace('Synthetic first-cycle studio');
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic first-cycle client', 'slug' => 'synthetic-first-cycle-client']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic quarterly monthly terms',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-16', 'billing_cadence' => 'quarterly',
            'retainer_minutes' => 600, 'retainer_amount' => 30000, 'hourly_rate_amount' => 10000,
            'first_cycle_proration' => $proration, 'rollover_months' => 0, 'bill_overage_interim' => false,
        ]);

        return [$workspace, $company, $agreement];
    }
}
