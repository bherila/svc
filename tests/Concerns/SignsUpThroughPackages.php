<?php

namespace Tests\Concerns;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * A client signing up for a synthetic package from the portal on a day in
 * January 2026, and the walk through its first billing periods - the engine's
 * own grid - generating and issuing each as it comes due. Shared by the
 * sign-up properties, split into classes so ParaTest, scheduling by class,
 * runs them side by side. Needs {@see BuildsServicePackages} and
 * {@see WalksBillingHistories} beside it.
 */
trait SignsUpThroughPackages
{
    /**
     * @param  array<string, mixed>  $terms  the package input overrides
     * @return array{Workspace, ClientCompany, ClientAgreement, CarbonImmutable}
     */
    private function signUpOn(int $day, array $terms): array
    {
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        if (($terms['charge_setup_fee_at_signup'] ?? false) || ($terms['charge_first_period'] ?? false)) {
            $this->connectStripe();
        }
        $start = CarbonImmutable::parse(sprintf('2026-01-%02d 10:00:00', $day), 'UTC');
        $this->travelTo($start);
        [$workspace, $owner] = $this->packageWorkspace('Synthetic sign-up studio');
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $packages = app(ServicePackageWorkflow::class);
        $package = $packages->publish($owner, $workspace, $packages->create($owner, $workspace, $this->hostingPackageInput(['link_name' => 'synthetic-signup', ...$terms])));
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic sign-up client', 'slug' => 'synthetic-signup-client']);
        $contact = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);
        $enrollments = app(PackageEnrollmentWorkflow::class);
        $enrollment = $enrollments->accept($contact, $company, $offer = $enrollments->addFromPortal($contact, $company, $package), $this->signatureFor($offer, 'Synthetic Signer'));
        $this->assertSame('active', $enrollment->refresh()->status);

        return [$workspace, $company, ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->sole(), $start];
    }

    /**
     * The agreement's first `$count` billing periods, as the engine lays them
     * out, each generated and issued on the day it starts.
     *
     * @return list<array{string, string}>
     */
    private function walkPeriods(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement, CarbonImmutable $start, int $count): array
    {
        $periods = [];
        foreach (app(BillingCycleResolver::class)->cyclesForAgreement($agreement, Carbon::parse($start->toDateString())->addYears(2 * $count)) as $cycle) {
            $periods[] = [$cycle->start->toDateString(), $cycle->end->toDateString()];
            if (count($periods) === $count) {
                break;
            }
        }
        $this->assertSame($start->toDateString(), $periods[0][0]);
        foreach ($periods as [$periodStart]) {
            $this->tickAndIssue($company, $workspace, CarbonImmutable::parse($periodStart.' 12:00:00', 'UTC'), null);
        }

        return $periods;
    }

    /**
     * The company's live cadence invoices, by the period they sell.
     *
     * @return Collection<int, ClientInvoice>
     */
    private function cadenceInvoices(Workspace $workspace, ClientCompany $company): Collection
    {
        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            ->where('status', '!=', InvoiceStatus::Void->value)->where('invoice_kind', InvoiceKind::CadencePeriod->value)->orderBy('id')->get()
            ->sortBy(fn (ClientInvoice $invoice): string => (string) $invoice->cycle_start?->toDateString())->values();
    }
}
