<?php

namespace Tests\Feature\ServicePackages;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Services\Engagement\AgreementCancellations;
use App\Services\Packages\PackageEnrollmentWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Automatic recurring invoices around a cancellation (EDGE-9, CAN-5): an
 * agreement whose end is scheduled is invoiced for every period up to the end,
 * the last one whole, and never for a period after it - on a monthly and a
 * quarterly cadence alike. A package beside it that was not cancelled is
 * invoiced as usual, so the difference is the cancellation's.
 *
 * The clock starts at 15 November 2026 in a UTC workspace.
 */
final class CancellationInvoicingTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', 'UTC'));
    }

    /** @return iterable<string, array{string, string, list<string>, string}> */
    public static function cadences(): iterable
    {
        // Notice of 30 days from Nov 15 runs out Dec 15.
        yield 'monthly' => ['monthly', '2026-12-31', ['2026-11-01', '2026-12-01'], '2027-01-01'];
        // Quarters anchored on the start (Nov 15): the first closes Feb 14.
        yield 'quarterly' => ['quarterly', '2027-02-14', ['2026-11-15'], '2027-02-15'];
    }

    /** @param list<string> $billed the period starts invoiced for the cancelled agreement */
    #[DataProvider('cadences')]
    #[Requirement('EDGE-9', 'CAN-5', 'INV-1')]
    public function test_no_period_after_a_scheduled_end_is_invoiced(string $cadence, string $end, array $billed, string $after): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $package = $this->publishedPackage($workspace, $owner, [
            'billing_cadence' => $cadence, 'invoice_issuing' => 'automatic', 'activation_mode' => 'automatic', 'cancellation_mode' => 'self_serve', 'notice_days' => 30,
            'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => $cadence, 'amount' => 4500]],
        ]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => 'lakeside-'.Str::uuid(), 'billing_email' => 'billing-lakeside@example.com']);
        $contact = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);
        $cancelled = $this->accept($contact, $company, $package);
        $kept = $this->accept($contact, $company, $package);
        $this->issueRun();

        $agreement = ClientAgreement::query()->findOrFail($cancelled->client_agreement_id);
        app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, $end, null);

        // Every run on every day from now until well past the end.
        for ($day = CarbonImmutable::parse('2026-11-16'); $day->lte(CarbonImmutable::parse($after)->addDays(40)); $day = $day->addDay()) {
            $this->travelTo($day->setTime(1, 0));
            $this->assertSame(0, Artisan::call('svc:agreements:terminate-scheduled', ['--apply' => true]));
            $this->issueRun();
        }

        $this->assertSame(['terminated', $end], [$agreement->refresh()->status, $agreement->ends_on?->toDateString()]);
        $invoices = ClientInvoice::query()->where('client_agreement_id', $agreement->id)->orderBy('cycle_start')->get();
        $this->assertSame($billed, $invoices->map(fn (ClientInvoice $invoice): ?string => $invoice->cycle_start?->toDateString())->all());
        // The last period is whole: it closes on the end.
        $this->assertSame($end, $invoices->last()?->cycle_end?->toDateString());
        // The package not cancelled went on being invoiced past that date.
        $this->assertTrue(ClientInvoice::query()->where('client_agreement_id', $kept->client_agreement_id)->whereDate('cycle_start', $after)->exists());
    }

    private function accept(User $contact, ClientCompany $company, ServicePackage $package): ServicePackageEnrollment
    {
        $workflow = app(PackageEnrollmentWorkflow::class);

        return $workflow->accept($contact, $company, $offer = $workflow->addFromPortal($contact, $company, $package), $this->signatureFor($offer))->refresh();
    }

    private function issueRun(): void
    {
        $this->assertSame(0, Artisan::call('svc:packages:issue-invoices', ['--apply' => true, '--format' => 'json']), Artisan::output());
    }
}
