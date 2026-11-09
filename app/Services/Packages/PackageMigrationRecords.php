<?php

namespace App\Services\Packages;

use App\Models\AgreementCancellationRequest;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Support\Billing\ChargeCadence;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\Engagement\Cancellation\CancellationRequestStatus;
use App\Support\Engagement\Cancellation\ScheduledTerminationStatus;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\Migration\ContinuedRecurrence;
use App\Support\Packages\Migration\MigrationDates;
use App\Support\Packages\Migration\MigrationSubscriber;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The tenant-scoped persistence boundary of a move (MIG-1, MIG-4): a
 * version's subscribers and the facts that decide each - its agreement,
 * company and autopay, the last period an issued invoice sold, any open
 * cancellation, and whether another move already holds it - in a fixed
 * number of bounded reads whatever the number of subscribers.
 *
 * The preview reads them plainly. Scheduling reads the chosen ones with
 * locks, in registry order - the enrollments, their scheduled targets, the
 * agreements, then the invoices - so what it checks is what it writes
 * against. The decisions themselves are {@see MigrationSubscriber}'s, pure.
 */
final class PackageMigrationRecords
{
    public function __construct(
        private readonly MigrationDates $dates = new MigrationDates,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * The version's subscribers - every active enrollment carrying it - or,
     * with `$enrollmentIds`, exactly those enrollments of the package
     * (whatever they carry, so a stale choice is refused, not dropped).
     *
     * @param  list<int>|null  $enrollmentIds
     * @return list<MigrationSubscriber> by enrollment id
     */
    public function subscribers(Workspace $workspace, ServicePackageVersion $from, ?array $enrollmentIds = null, bool $locking = false): array
    {
        $query = ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->where('service_package_id', $from->service_package_id);
        if ($enrollmentIds === null) {
            $query->where('status', EnrollmentStatus::Active->value)->whereRaw(ServicePackageEnrollment::CARRIED_VERSION_SQL.' = ?', [$from->id]);
        } else {
            $query->whereIn('id', $enrollmentIds === [] ? [0] : $enrollmentIds);
        }
        $enrollments = $query->orderBy('id')->when($locking, fn (Builder $locked) => $locked->tap(Locks::forUpdate()))->get();
        if ($enrollments->isEmpty()) {
            return [];
        }
        $ids = $enrollments->modelKeys();
        $agreementIds = array_values(array_unique($enrollments->pluck('client_agreement_id')->map(static fn (mixed $id): int => (int) $id)->all()));

        $held = ServicePackageMigrationTarget::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('service_package_enrollment_id', $ids)
            ->where('status', MigrationTargetStatus::Scheduled->value)
            ->orderBy('id')
            ->when($locking, fn (Builder $locked) => $locked->tap(Locks::forShare()))
            ->pluck('service_package_enrollment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $agreementIds)
            ->orderBy('id')
            ->when($locking, fn (Builder $locked) => $locked->tap(Locks::forShare()))
            ->get()
            ->keyBy('id');
        $invoicedThrough = $this->invoicedThrough($workspace, $agreementIds, $locking);
        [$pending, $scheduled] = $locking ? [[], []] : $this->openCancellations($workspace, $agreementIds);
        $today = $this->clock->today($workspace);
        // The open recurring items whose schedule a move would continue
        // (ContinuedRecurrence). Never locked anywhere, so an ordinary read.
        $items = ClientAgreementRecurringItem::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_agreement_id', $agreementIds)
            ->where('is_active', true)
            ->where('cadence', '!=', ChargeCadence::OneTime->value)
            ->where(fn ($open) => $open->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today->toDateString()))
            ->orderBy('id')
            ->get(['id', 'client_agreement_id', 'cadence', 'effective_on', 'anchor_day', 'anchor_month'])
            ->groupBy('client_agreement_id');
        $companies = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_unique($enrollments->pluck('client_company_id')->all())))
            ->get(['id', 'public_id', 'workspace_id', 'name', 'currency', 'autopay_enabled_at', 'autopay_limit_amount'])
            ->keyBy('id');

        $subscribers = [];
        foreach ($enrollments as $enrollment) {
            $agreement = $agreements->get($enrollment->client_agreement_id);
            $company = $companies->get($enrollment->client_company_id);
            if (! $agreement instanceof ClientAgreement || ! $company instanceof ClientCompany) {
                continue;
            }
            $recurring = $agreement->billsOnARecurringCadence();
            $subscribers[] = new MigrationSubscriber(
                enrollment: $enrollment,
                agreement: $agreement,
                company: $company,
                activeOnFromVersion: $enrollment->enrollmentStatus() === EnrollmentStatus::Active
                    && $enrollment->carriedVersionId() === $from->id
                    && $agreement->status === 'active',
                alreadyScheduled: in_array($enrollment->id, $held, true),
                dates: $recurring ? $this->dates->offer(
                    $agreement->effectiveBillingCadence(),
                    $agreement->starts_on,
                    (int) $agreement->notice_days,
                    $today,
                    $agreement->ends_on,
                    $invoicedThrough[$agreement->id] ?? null,
                ) : null,
                autopayLimit: $company->autopay_enabled_at !== null && $company->autopay_limit_amount !== null ? (int) $company->autopay_limit_amount : null,
                pendingEndOn: $pending[$agreement->id] ?? null,
                scheduledEndOn: $scheduled[$agreement->id] ?? null,
                scheduleItems: self::scheduleFacts($items->get($agreement->id, new Collection)),
            );
        }

        return $subscribers;
    }

    /**
     * The last day of the last period an issued invoice (any status but draft
     * and void) sold, per agreement. A move starts after it, so no period is
     * billed twice and no issued invoice is rewritten.
     *
     * @param  list<int>  $agreementIds
     * @return array<int, CarbonImmutable>
     */
    public function invoicedThrough(Workspace $workspace, array $agreementIds, bool $locking = false): array
    {
        if ($agreementIds === []) {
            return [];
        }
        $through = [];
        foreach (ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_agreement_id', $agreementIds)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->whereNotNull('cycle_end')
            ->orderBy('id')
            ->when($locking, fn (Builder $locked) => $locked->tap(Locks::forShare()))
            ->get(['id', 'client_agreement_id', 'cycle_end']) as $invoice) {
            $agreementId = (int) $invoice->client_agreement_id;
            $end = $invoice->cycle_end === null ? null : CarbonImmutable::parse($invoice->cycle_end->toDateString(), 'UTC');
            if ($end !== null && (! isset($through[$agreementId]) || $end->gt($through[$agreementId]))) {
                $through[$agreementId] = $end;
            }
        }

        return $through;
    }

    /**
     * The facts of recurring items that set a charge schedule, as
     * {@see ContinuedRecurrence::fromItems()} reads them.
     *
     * @param  iterable<ClientAgreementRecurringItem>  $items
     * @return list<array{cadence: string, effective_on: CarbonImmutable|null, anchor_day: int|null, anchor_month: int|null}>
     */
    public static function scheduleFacts(iterable $items): array
    {
        $facts = [];
        foreach ($items as $item) {
            $facts[] = ['cadence' => (string) $item->cadence, 'effective_on' => $item->start_date, 'anchor_day' => $item->anchor_day, 'anchor_month' => $item->anchor_month];
        }

        return $facts;
    }

    /**
     * Each agreement's pending cancellation request's end and scheduled end,
     * for the preview.
     *
     * @param  list<int>  $agreementIds
     * @return array{array<int, CarbonImmutable>, array<int, CarbonImmutable>}
     */
    private function openCancellations(Workspace $workspace, array $agreementIds): array
    {
        $pending = [];
        foreach (AgreementCancellationRequest::query()->where('workspace_id', $workspace->id)->whereIn('client_agreement_id', $agreementIds)
            ->where('status', CancellationRequestStatus::Pending->value)->orderBy('id')->get(['id', 'client_agreement_id', 'requested_end_on']) as $request) {
            $pending[(int) $request->client_agreement_id] = $request->requested_end_on;
        }
        $scheduled = [];
        foreach (AgreementScheduledTermination::query()->where('workspace_id', $workspace->id)->whereIn('client_agreement_id', $agreementIds)
            ->where('status', ScheduledTerminationStatus::Scheduled->value)->orderBy('id')->get(['id', 'client_agreement_id', 'ends_on']) as $end) {
            $scheduled[(int) $end->client_agreement_id] = $end->ends_on;
        }

        return [$pending, $scheduled];
    }
}
