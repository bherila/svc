<?php

namespace App\Support\Packages\Migration;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ServicePackage;
use App\Models\ServicePackageMigration;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Autopay\AutopayWording;
use App\Support\Packages\PackageEnrollmentPresenter;
use App\Support\Packages\PackageTerms;
use LogicException;

/**
 * The shapes of a move (MIG-1 to MIG-5): the owner's and admin's
 * (`package_migrations.*`, the editor), what an enrollment says about the
 * move it is part of (`enrollments.*`), and the client's notice
 * (`client_services.*` and the portal's service page, MIG-2).
 *
 * Reads only what the caller loaded, so it issues no queries; URLs are
 * finished here for the reader the caller authorized.
 */
final class PackageMigrationPresenter
{
    /** @return array<string, mixed> */
    public static function forManager(Workspace $workspace, ServicePackageMigration $migration): array
    {
        $package = $migration->package;
        $from = $migration->fromVersion;
        $to = $migration->toVersion;
        if (! $package instanceof ServicePackage || ! $from instanceof ServicePackageVersion || ! $to instanceof ServicePackageVersion) {
            throw new LogicException('A move names a record outside its workspace, or was presented without its relations loaded.');
        }
        $toTerms = PackageTerms::fromVersion($to);
        $targets = $migration->targets;
        $counts = array_fill_keys(array_map(static fn (MigrationTargetStatus $status): string => $status->value, MigrationTargetStatus::cases()), 0);
        foreach ($targets as $target) {
            $counts[$target->status]++;
        }
        $price = $targets->first()?->new_price_amount;

        return [
            'id' => $migration->public_id,
            'status' => $migration->status,
            'package' => ['id' => $package->public_id, 'link_name' => $package->link_name, 'name' => $toTerms->name],
            'from_version' => ['id' => $from->public_id, 'number' => $from->number],
            'to_version' => ['id' => $to->public_id, 'number' => $to->number],
            'effective_on' => $migration->effective_on->toDateString(),
            'message' => $migration->message,
            'changes' => MigrationTermChanges::forOwner(PackageTerms::fromVersion($from), $toTerms),
            'new_price' => $price === null ? null : ['amount' => $price, 'currency' => $toTerms->currency, 'label' => AutopayWording::money($price, $toTerms->currency)],
            'scheduled_at' => $migration->scheduled_at->toIso8601String(),
            'scheduled_by' => $migration->scheduledBy?->name,
            'cancelled_at' => $migration->cancelled_at?->toIso8601String(),
            'completed_at' => $migration->completed_at?->toIso8601String(),
            'counts' => $counts,
            'subscribers' => array_values($targets->map(static fn (ServicePackageMigrationTarget $target): array => self::target($workspace, $target))->all()),
            'version' => AgentApiVersion::for($migration),
        ];
    }

    /**
     * Where one enrollment's open move stands, for owners and admins
     * (`enrollments.*`): the move, its date, the version it moves to, and why
     * it waits, if it does. Null when it is part of no scheduled move.
     *
     * @return array<string, mixed>|null
     */
    public static function forEnrollment(?ServicePackageMigrationTarget $target): ?array
    {
        if ($target === null) {
            return null;
        }
        $migration = $target->migration;
        $to = $migration?->toVersion;
        if (! $migration instanceof ServicePackageMigration || ! $to instanceof ServicePackageVersion) {
            throw new LogicException('A move names a record outside its workspace, or was presented without its relations loaded.');
        }

        return [
            'migration_id' => $migration->public_id,
            'status' => $target->status,
            'effective_on' => $target->effective_on->toDateString(),
            'to_version' => ['id' => $to->public_id, 'number' => $to->number],
            'outcome' => self::outcome($target),
        ];
    }

    /**
     * The client's notice of a scheduled move (MIG-2): the date, what changes
     * from what to what, the new terms in full, the owner's message, and
     * whether their autopay limit is below the new price (MIG-4).
     *
     * @return array<string, mixed>|null
     */
    public static function forClient(?ServicePackageMigrationTarget $target, PackageTerms $current, ?ClientCompany $company): ?array
    {
        if ($target === null) {
            return null;
        }
        $migration = $target->migration;
        $to = $migration?->toVersion;
        if (! $migration instanceof ServicePackageMigration || ! $to instanceof ServicePackageVersion) {
            throw new LogicException('A move names a record outside its workspace, or was presented without its relations loaded.');
        }
        $toTerms = PackageTerms::fromVersion($to);
        $limit = $company?->autopay_enabled_at !== null && $company?->autopay_limit_amount !== null ? (int) $company->autopay_limit_amount : null;

        return [
            'effective_on' => $target->effective_on->toDateString(),
            'version_number' => $to->number,
            'changes' => MigrationTermChanges::forClient($current, $toTerms),
            'terms' => PackageEnrollmentPresenter::clientTerms($toTerms),
            'message' => $migration->message,
            'new_price_amount' => $target->new_price_amount,
            'autopay_limit_amount' => $limit,
            'autopay_below_price' => MigrationRules::autopayBelowPrice($limit, $target->new_price_amount),
        ];
    }

    /** @return array<string, mixed> */
    private static function target(Workspace $workspace, ServicePackageMigrationTarget $target): array
    {
        $company = $target->clientCompany;
        $agreement = $target->agreement;
        if (! $company instanceof ClientCompany || ! $agreement instanceof ClientAgreement) {
            throw new LogicException('A move names a record outside its workspace, or was presented without its relations loaded.');
        }
        $successor = $target->successor_agreement_id === null ? null : $target->successorAgreement;
        $base = rtrim((string) config('app.url'), '/');
        $url = static fn (ClientAgreement $record): string => $base.route('clients.agreement', ['workspace' => $workspace, 'clientCompany' => $company, 'clientAgreement' => $record], false);

        return [
            'id' => $target->public_id,
            'enrollment_id' => $target->enrollment?->public_id,
            'client' => ['id' => $company->public_id, 'name' => $company->name],
            'agreement' => ['id' => $agreement->public_id, 'title' => $agreement->title, 'url' => $url($agreement)],
            'successor_agreement' => $successor instanceof ClientAgreement ? ['id' => $successor->public_id, 'title' => $successor->title, 'url' => $url($successor)] : null,
            'status' => $target->status,
            'outcome' => self::outcome($target),
            'autopay_limit_amount' => $target->autopay_limit_amount,
            'autopay_below_price' => MigrationRules::autopayBelowPrice($target->autopay_limit_amount, $target->new_price_amount),
            'settled_at' => $target->settled_at?->toIso8601String(),
        ];
    }

    /** @return array{code: string, message: string, detail: string|null}|null */
    private static function outcome(ServicePackageMigrationTarget $target): ?array
    {
        if ($target->outcome_code === null) {
            return null;
        }
        $message = MigrationSkip::tryFrom($target->outcome_code)?->message() ?? MigrationHold::tryFrom($target->outcome_code)?->message() ?? $target->outcome_code;

        return ['code' => $target->outcome_code, 'message' => $message, 'detail' => $target->outcome_detail === $message ? null : $target->outcome_detail];
    }
}
