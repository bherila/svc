<?php

namespace App\Services\Packages;

use App\Exceptions\PackageMigrationRefused;
use App\Models\AgentPrincipal;
use App\Models\ServicePackage;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\Billing\Autopay\AutopayWording;
use App\Support\Packages\Migration\MigrationRefusal;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationSubscriber;
use App\Support\Packages\Migration\MigrationTermChanges;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageComponentTerms;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PublicPackagePresenter;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What moving a version's subscribers to the package's published version
 * would do, changing nothing (`package_migrations.preview`, MIG-1, MIG-4):
 * the terms that change, each subscriber's earliest and offered effective
 * dates, whether the chosen date suits it, and whether its autopay limit is
 * below the new price. A subscriber refused on every date it is offered
 * ({@see MigrationSubscriber::refusalOnEveryDate()}) is listed apart, in
 * `refused`, with why. The effective dates on offer are the others', each
 * with how many of them it suits; with no date chosen, the earliest date that
 * suits the most is suggested.
 *
 * {@see PackageMigrationWorkflow::schedule()} decides with the same rules
 * under its locks; this is the same reading, unlocked.
 */
final class PackageMigrationPlanner
{
    /** How many effective dates the preview offers at most, across subscribers. */
    public const int MAX_DATES = 12;

    public function __construct(
        private readonly AgentAccess $access,
        private readonly PackageMigrationRecords $records,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User|AgentPrincipal $actor, Workspace $workspace, ServicePackage $package, string $fromVersionId, ?string $effectiveOn): array
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
        $chosen = $effectiveOn === null ? null : self::date($effectiveOn);
        $from = self::fromVersion($workspace, $package, $fromVersionId);
        $to = self::toVersion($workspace, $package);
        $fromTerms = PackageTerms::fromVersion($from);
        $toTerms = $to === null ? null : PackageTerms::fromVersion($to);
        $refusal = self::packageRefusal($package, $from, $to, $fromTerms, $toTerms);
        $subscribers = $refusal === null ? $this->records->subscribers($workspace, $from) : [];
        $price = $toTerms === null ? null : PublicPackagePresenter::cycleAmount($toTerms);

        $movable = [];
        $refused = [];
        // A refused package reads no subscriber, and with no version to move to it is refused.
        foreach ($toTerms === null ? [] : $subscribers as $subscriber) {
            $cadence = $toTerms->billingCadence;
            $standing = $subscriber->refusalOnEveryDate($cadence);
            if ($standing === null) {
                $movable[] = [$subscriber, $subscriber->movableDates($cadence)];
            } else {
                $refused[] = self::refused($subscriber, $standing);
            }
        }

        $dates = self::dates($movable);
        $suggested = $chosen?->toDateString() ?? self::suggested($dates);
        $date = $suggested === null ? null : CarbonImmutable::parse($suggested, 'UTC');

        return [
            'package' => ['id' => $package->public_id, 'link_name' => $package->link_name, 'name' => ($toTerms ?? $fromTerms)->name],
            'from_version' => ['id' => $from->public_id, 'number' => $from->number],
            'to_version' => $to === null ? null : ['id' => $to->public_id, 'number' => $to->number],
            'refusal' => $refusal?->toArray(),
            'changes' => $toTerms === null ? [] : MigrationTermChanges::forOwner($fromTerms, $toTerms),
            'new_price' => $toTerms === null || $price === null ? null : [
                'amount' => $price, 'currency' => $toTerms->currency, 'label' => AutopayWording::money($price, $toTerms->currency),
            ],
            'effective_dates' => array_map(static fn (string $day, int $count): array => ['date' => $day, 'subscribers' => $count], array_keys($dates), array_values($dates)),
            'effective_on' => $suggested,
            'subscribers' => $toTerms === null ? [] : array_map(static fn (array $row): array => self::subscriber($row[0], $row[1], $date, $price, $toTerms), $movable),
            'refused' => $refused,
        ];
    }

    /**
     * Why no subscriber of `$from` can move to `$to` at all, or null: only to
     * the published version of a published package, from an older one, both
     * billing in periods, on the same cadence and in the same currency.
     */
    public static function packageRefusal(ServicePackage $package, ServicePackageVersion $from, ?ServicePackageVersion $to, PackageTerms $fromTerms, ?PackageTerms $toTerms): ?MigrationRefusal
    {
        return match (true) {
            $package->packageStatus() !== PackageStatus::Published, $to === null, $toTerms === null, $to->number <= $from->number => MigrationRefusal::NotNewer,
            ! $fromTerms->isRecurring() || ! $toTerms->isRecurring() => MigrationRefusal::NotRecurring,
            $fromTerms->billingCadence !== $toTerms->billingCadence => MigrationRefusal::ChangesCadence,
            self::chargesOffCycle($fromTerms) || self::chargesOffCycle($toTerms) => MigrationRefusal::OffCycleComponent,
            strtoupper($fromTerms->currency) !== strtoupper($toTerms->currency) => MigrationRefusal::CurrencyChanged,
            default => null,
        };
    }

    /**
     * Whether a version has a recurring component off its billing cycle (an
     * annual licence in a monthly service, a one-time charge). Its setup fee
     * is not one: a move never carries it ({@see PackageAgreementTerms}).
     */
    public static function chargesOffCycle(PackageTerms $terms): bool
    {
        return MigrationRules::hasOffCycleCharge($terms->billingCadence->value, array_values(array_map(
            static fn (PackageComponentTerms $component): string => $component->cadence->value,
            array_filter($terms->components, static fn (PackageComponentTerms $component): bool => $component->kind === PackageComponentKind::Recurring),
        )));
    }

    /** A published version of this package, by its public id; not found otherwise. */
    public static function fromVersion(Workspace $workspace, ServicePackage $package, string $publicId): ServicePackageVersion
    {
        return ServicePackageVersion::query()
            ->where('workspace_id', $workspace->id)
            ->where('service_package_id', $package->id)
            ->where('public_id', $publicId)
            ->whereNull('draft_marker')
            ->whereNotNull('published_at')
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])
            ->firstOrFail();
    }

    /** The package's published version, with its components, or null. */
    public static function toVersion(Workspace $workspace, ServicePackage $package): ?ServicePackageVersion
    {
        return $package->published_version_id === null ? null : ServicePackageVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($package->published_version_id)
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])
            ->first();
    }

    /**
     * Whether the company's autopay would stop short of the new price (MIG-4):
     * on with a lower limit, or with its limit in another currency.
     */
    public static function autopayBelowPrice(MigrationSubscriber $subscriber, ?int $price, ?string $currency): bool
    {
        if ($price === null || $subscriber->autopayLimit === null) {
            return false;
        }

        return strtoupper((string) ($subscriber->company->currency ?? $currency)) !== strtoupper((string) $currency)
            || MigrationRules::autopayBelowPrice($subscriber->autopayLimit, $price);
    }

    public static function date(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('An effective date is a calendar day, YYYY-MM-DD.');
        }

        return $date;
    }

    /**
     * A subscriber some offered date suits: those dates, and why it cannot
     * move on the chosen one, if it cannot.
     *
     * @param  list<CarbonImmutable>  $days  its movable dates, never empty
     * @return array<string, mixed>
     */
    private static function subscriber(MigrationSubscriber $subscriber, array $days, ?CarbonImmutable $date, ?int $price, PackageTerms $toTerms): array
    {
        $refusal = $subscriber->refusal($date, $toTerms->billingCadence);
        $dates = array_map(static fn (CarbonImmutable $day): string => $day->toDateString(), $days);

        return [
            ...self::identity($subscriber),
            'earliest_effective_on' => $dates[0] ?? null,
            'effective_dates' => $dates,
            'autopay_limit_amount' => $subscriber->autopayLimit,
            'autopay_below_price' => self::autopayBelowPrice($subscriber, $price, $toTerms->currency),
            'pending_cancellation_end_on' => $subscriber->pendingEndOn?->toDateString(),
            'scheduled_end_on' => $subscriber->scheduledEndOn?->toDateString(),
            'refusal' => $refusal?->toArray(),
        ];
    }

    /**
     * A subscriber no offered date suits, with why.
     *
     * @return array<string, mixed>
     */
    private static function refused(MigrationSubscriber $subscriber, MigrationRefusal $refusal): array
    {
        return [...self::identity($subscriber), 'refusal' => $refusal->toArray()];
    }

    /**
     * Who a subscriber is: its service, client and agreement.
     *
     * @return array<string, mixed>
     */
    private static function identity(MigrationSubscriber $subscriber): array
    {
        $agreement = $subscriber->agreement;

        return [
            'enrollment_id' => $subscriber->enrollment->public_id,
            'client' => ['id' => $subscriber->company->public_id, 'name' => $subscriber->company->name],
            'agreement' => [
                'id' => $agreement->public_id,
                'title' => $agreement->title,
                'starts_on' => $agreement->starts_on->toDateString(),
                'ends_on' => $agreement->ends_on?->toDateString(),
            ],
        ];
    }

    /**
     * Every effective date some subscriber may move on, ascending, with how
     * many subscribers it suits: only dates the planner accepts for each, so
     * a refused subscriber counts towards none.
     *
     * @param  list<array{0: MigrationSubscriber, 1: list<CarbonImmutable>}>  $movable  each subscriber with its movable dates
     * @return array<string, int>
     */
    private static function dates(array $movable): array
    {
        $counts = [];
        foreach ($movable as [, $days]) {
            foreach ($days as $day) {
                $counts[$day->toDateString()] = ($counts[$day->toDateString()] ?? 0) + 1;
            }
        }
        ksort($counts);

        return array_slice($counts, 0, self::MAX_DATES, true);
    }

    /**
     * The earliest date that suits the most subscribers, or null with none.
     *
     * @param  array<string, int>  $dates
     */
    private static function suggested(array $dates): ?string
    {
        $best = null;
        foreach ($dates as $day => $count) {
            if ($best === null || $count > $dates[$best]) {
                $best = (string) $day;
            }
        }

        return $best;
    }

    /** @throws PackageMigrationRefused */
    public static function refuse(MigrationRefusal $refusal, ?string $detail = null): never
    {
        throw PackageMigrationRefused::because($refusal, $detail);
    }
}
