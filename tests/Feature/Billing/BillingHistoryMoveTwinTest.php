<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\PackageMigrationPlanner;
use App\Services\Packages\PackageMigrationWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\WalksBillingHistories;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * The moved-and-never-moved twin property of a package move (MIG-3), one of
 * the billing-history invariants ({@see BillingHistoryInvariantTest}) in a
 * class of its own: its 36 worlds each walk three twins through a year or
 * more of billing, and ParaTest schedules by class, so beside the other
 * histories it set its shard's duration (#686).
 */
final class BillingHistoryMoveTwinTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;
    use WalksBillingHistories;

    /** @return iterable<string, array{string, int, string}> */
    public static function continuedScheduleWorlds(): iterable
    {
        foreach (['monthly' => 4500, 'quarterly' => 12000, 'semi_annual' => 24000, 'annual' => 48000] as $cadence => $amount) {
            foreach ([1, 9, 31] as $day) {
                foreach (['one component', 'two components', 'a setup fee with the first cycle'] as $components) {
                    yield "{$cadence}, started on day {$day}, {$components}" => [$cadence, $day, $components];
                }
            }
        }
    }

    /**
     * Property (docs/service-packages.md, MIG-3): for identical terms, a
     * service moved to a new version bills exactly as the same service never
     * moved - the same total for every period, and every period exactly
     * once - on every cadence, whatever day of the month it started, for each
     * of the first two dates a move may take (one full cycle apart), with one
     * or two recurring components on its cadence or a setup fee billed with
     * the first cycle. Version 2 differs from version 1 only in wording, so
     * nothing a client pays may change (Codex P1 on #674: a quarterly service
     * started Nov 9 and moved Feb 9 was charged Feb 9 and May 1 in its first
     * new quarter, where the never-moved twin is charged May 1 alone).
     */
    #[DataProvider('continuedScheduleWorlds')]
    #[Requirement('MIG-3')]
    public function test_a_move_to_identical_terms_bills_every_period_as_never_moving(string $cadence, int $day, string $components): void
    {
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $amounts = ['monthly' => 4500, 'quarterly' => 12000, 'semi_annual' => 24000, 'annual' => 48000];
        $months = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6, 'annual' => 12][$cadence];
        $terms = [
            'billing_cadence' => $cadence, 'invoice_issuing' => 'draft', 'activation_mode' => 'automatic', 'notice_days' => 0, 'charge_setup_fee_at_signup' => false,
            'components' => [
                ['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => $cadence, 'amount' => $amounts[$cadence]],
                ...match ($components) {
                    'one component' => [],
                    'two components' => [['kind' => 'recurring', 'description' => 'Synthetic backups', 'cadence' => $cadence, 'amount' => 700]],
                    'a setup fee with the first cycle' => [['kind' => 'setup_fee', 'description' => 'Synthetic setup', 'amount' => 15000]],
                },
            ],
        ];
        $start = CarbonImmutable::parse(sprintf('2026-01-%02d 10:00:00', $day), 'UTC');

        $billed = [];
        $dates = [];
        foreach (['never moved', 'moved on the first date', 'moved on the second date'] as $index => $twin) {
            $this->travelTo($start);
            [$workspace, $owner] = $this->packageWorkspace('Synthetic schedule '.$twin);
            $workspace->forceFill(['timezone' => 'UTC'])->save();
            $packages = app(ServicePackageWorkflow::class);
            $package = $packages->publish($owner, $workspace, $packages->create($owner, $workspace, $this->hostingPackageInput(['link_name' => 'synthetic-schedule', ...$terms])));
            $from = ServicePackageVersion::query()->findOrFail($package->published_version_id);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic schedule client', 'slug' => 'synthetic-schedule-'.$index]);
            $contact = User::factory()->create();
            ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);
            $enrollments = app(PackageEnrollmentWorkflow::class);
            $enrollment = $enrollments->accept($contact, $company, $offer = $enrollments->addFromPortal($contact, $company, $package), $this->signatureFor($offer, 'Synthetic Signer'));
            $packages->update($owner, $workspace, $package->refresh(), ['summary' => 'Synthetic summary, reworded.']);
            $package = $packages->publish($owner, $workspace, $package->refresh());
            if ($dates === []) {
                $dates = array_slice(app(PackageMigrationPlanner::class)->preview($owner, $workspace, $package, $from->public_id, null)['subscribers'][0]['effective_dates'], 0, 2);
                $this->assertCount(2, $dates);
            }
            $end = CarbonImmutable::parse($dates[1])->addMonthsNoOverflow(2 * $months)->setTime(6, 0);
            $this->tickAndIssue($company, $workspace, $start->setTime(12, 0), null);

            if ($index > 0) {
                $effective = $dates[$index - 1];
                app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, $effective, [$enrollment->public_id], null);
                $this->tickAndIssue($company, $workspace, CarbonImmutable::parse($effective)->subDay()->setTime(12, 0), $effective);
                $this->travelTo(CarbonImmutable::parse($effective)->setTime(0, 30));
                $this->assertSame(0, Artisan::call('svc:packages:apply-migrations', ['--apply' => true]), Artisan::output());
                $this->assertSame('applied', ServicePackageMigrationTarget::query()->where('workspace_id', $workspace->id)->sole()->status, $twin);
                $this->tickAndIssue($company, $workspace, CarbonImmutable::parse($effective)->setTime(1, 0), null);
            }
            $this->tickAndIssue($company, $workspace, $end, null);

            $invoices = ClientInvoice::query()->where('client_company_id', $company->id)->where('status', '!=', InvoiceStatus::Void->value)->orderBy('cycle_start')->orderBy('id')->get();
            $periods = $invoices->map(fn (ClientInvoice $invoice): string => (string) $invoice->cycle_start?->toDateString())->all();
            $this->assertSame(array_values(array_unique($periods)), $periods, "{$twin}: every period invoiced once.");
            $billed[$twin] = $invoices->mapWithKeys(fn (ClientInvoice $invoice): array => [(string) $invoice->cycle_start?->toDateString() => (int) $invoice->total_amount])->all();
        }

        $this->assertNotSame([], $billed['never moved']);
        $this->assertSame($billed['never moved'], $billed['moved on the first date'], "Moved on {$dates[0]}.");
        $this->assertSame($billed['never moved'], $billed['moved on the second date'], "Moved on {$dates[1]}.");
    }
}
