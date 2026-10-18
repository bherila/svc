<?php

namespace App\Support\AgentApi\Presenters;

use App\Support\Billing\DeferredBacklog;
use App\Support\Billing\DeferredBacklogMonth;

/**
 * Allowlisted serialization of the deferred backlog read model.
 *
 * The one shape REST, MCP, the operations summary, the admin agreement page
 * and the artisan command all print. Hours to four places, as the capacity
 * ledger states them; the amount in minor units beside its currency. Internal
 * entry ids are never presented.
 */
final class DeferredBacklogPresenter
{
    /** @return array<string, mixed> */
    public function present(DeferredBacklog $backlog): array
    {
        return [
            'through' => $backlog->through,
            'hours' => $backlog->hours(),
            'entry_count' => $backlog->entryCount,
            'oldest_worked_on' => $backlog->oldestWorkedOn,
            'agreement_rate_hours' => $backlog->agreementRateHours(),
            'flat_hourly_hours' => $backlog->flatHourlyHours(),
            'amount_at_hourly_rate' => $backlog->amount === null
                ? null
                : ['currency' => $backlog->currency, 'amount' => $backlog->amount],
            'issue' => $backlog->issue?->value,
            'issue_message' => $backlog->issue?->message(),
            'issue_detail' => $backlog->issueDetail,
            'net_hours_per_month' => $backlog->netHoursPerMonth(),
            'trend' => array_map(static fn (DeferredBacklogMonth $month): array => [
                'period' => $month->period,
                'hours_added' => round($month->minutesAdded / 60, 4),
                'hours_applied' => round($month->minutesApplied / 60, 4),
                'hours_billed' => round($month->minutesBilled / 60, 4),
            ], $backlog->trend),
        ];
    }
}
