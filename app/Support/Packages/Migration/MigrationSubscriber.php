<?php

namespace App\Support\Packages\Migration;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ServicePackageEnrollment;
use App\Services\Packages\PackageMigrationRecords;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One subscriber a move could take, with the facts that decide it, read once
 * by {@see PackageMigrationRecords} so the decisions
 * are made without a database (MIG-1, MIG-4).
 */
final readonly class MigrationSubscriber
{
    public function __construct(
        public ServicePackageEnrollment $enrollment,
        public ClientAgreement $agreement,
        public ClientCompany $company,
        /** An active service, on an active agreement, carrying the version moved from. */
        public bool $activeOnFromVersion,
        /** Already a subscriber of another scheduled move. */
        public bool $alreadyScheduled,
        /** The dates it may move on; null when its agreement does not bill in periods. */
        public ?MigrationEffectiveDates $dates,
        /** The company's autopay limit while autopay is on, in its billing currency. */
        public ?int $autopayLimit,
        /** The end a pending cancellation request asks for, if any. */
        public ?CarbonImmutable $pendingEndOn,
        /** The end a scheduled cancellation set, if any. */
        public ?CarbonImmutable $scheduledEndOn,
    ) {}

    public function refusal(?CarbonInterface $chosen): ?MigrationRefusal
    {
        $agreement = $this->agreement;

        return MigrationRules::eligibility($this->activeOnFromVersion, $this->alreadyScheduled, $this->dates, $chosen, MigrationRules::billsByUsage(
            $agreement->hourly_rate_amount, $agreement->retainer_amount, $agreement->retainer_minutes, $agreement->period_retainer_amount, $agreement->period_retainer_minutes,
        ));
    }
}
