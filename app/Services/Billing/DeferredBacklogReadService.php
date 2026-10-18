<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\AgentApi\Presenters\DeferredBacklogPresenter;
use App\Support\Billing\DeferredBacklog;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;

/**
 * The one read workflow behind every surface that shows a deferred backlog.
 *
 * `capacity_ledger.get` (REST and MCP), `operations.summary`, the admin
 * agreement page and `svc:billing:deferred-backlog` are adapters over this:
 * each authorizes its own principal, then asks here, so the figure, its trend
 * window and its serialization are decided once. The calculation itself is
 * {@see DeferredBacklog}, over {@see DeferredBacklogReader}'s bounded load.
 */
final class DeferredBacklogReadService
{
    /** The agreements an operations summary reads, newest first. */
    public const int SUMMARY_SCAN_LIMIT = 200;

    /** The agreements an operations summary lists, largest backlog first. */
    public const int SUMMARY_LIST_LIMIT = 25;

    /** Calendar months the admin agreement page reads the trend over. */
    public const int PAGE_TREND_MONTHS = 3;

    public function __construct(
        private readonly DeferredBacklogReader $reader,
        private readonly DeferredBacklogPresenter $presenter,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * One agreement's backlog as of a given day, its trend over given months.
     *
     * @param  list<string>  $periods  `Y-m` trend months, oldest first
     * @return array<string, mixed>
     */
    public function forAgreement(Workspace $workspace, ClientAgreement $agreement, array $periods, CarbonImmutable $through): array
    {
        return $this->presenter->present($this->reader->forAgreement($workspace, $agreement, $through, $periods));
    }

    /**
     * One agreement's backlog as of the workspace's today, its trend over the
     * trailing calendar months ending with this one.
     *
     * @return array<string, mixed>
     */
    public function trailing(Workspace $workspace, ClientAgreement $agreement, int $months = self::PAGE_TREND_MONTHS): array
    {
        $through = $this->clock->today($workspace);

        return $this->forAgreement($workspace, $agreement, self::trailingPeriods($through, $months), $through);
    }

    /**
     * The workspace's active and terminated agreements that still have work
     * on the shelf, largest first. Read in one bounded pass; no trend.
     *
     * @return array{agreement_count:int, truncated:bool, agreements:list<array<string, mixed>>}
     */
    public function summary(Workspace $workspace): array
    {
        $through = $this->clock->today($workspace);
        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['active', 'terminated'])
            ->with(['clientCompany' => fn ($company) => $company->where('workspace_id', $workspace->id)])
            ->orderByDesc('id')
            ->limit(self::SUMMARY_SCAN_LIMIT)
            ->get();
        $byId = $agreements->keyBy('id');

        /** @var list<array{DeferredBacklog, ClientAgreement, ClientCompany}> $listed */
        $listed = [];
        foreach ($this->reader->forAgreements($workspace, $agreements, $through) as $agreementId => $backlog) {
            $agreement = $byId->get($agreementId);
            $company = $agreement?->clientCompany;
            if (! $backlog->hasBacklog() || ! $agreement instanceof ClientAgreement || ! $company instanceof ClientCompany) {
                continue;
            }
            $listed[] = [$backlog, $agreement, $company];
        }
        // Withheld figures first - they need a correction - then the largest
        // shelves, then the oldest agreement, so the order is stable.
        usort($listed, static fn (array $a, array $b): int => [$a[0]->minutes === null ? 0 : 1, -($a[0]->minutes ?? 0), $a[1]->id]
            <=> [$b[0]->minutes === null ? 0 : 1, -($b[0]->minutes ?? 0), $b[1]->id]);
        $base = rtrim(config()->string('app.url'), '/');

        return [
            'agreement_count' => count($listed),
            'truncated' => count($listed) > self::SUMMARY_LIST_LIMIT || $agreements->count() >= self::SUMMARY_SCAN_LIMIT,
            'agreements' => array_map(fn (array $row): array => [
                'agreement_id' => $row[1]->public_id,
                'client_id' => $row[2]->public_id,
                'web_url' => $base.route('clients.agreement', [$workspace, $row[2], $row[1]], absolute: false),
                'backlog' => $this->presenter->present($row[0]),
            ], array_slice($listed, 0, self::SUMMARY_LIST_LIMIT)),
        ];
    }

    /**
     * The `count` calendar months ending with the one `through` falls in.
     *
     * @return list<string>
     */
    public static function trailingPeriods(CarbonImmutable $through, int $count): array
    {
        $periods = [];
        $month = $through->startOfMonth();
        for ($i = $count - 1; $i >= 0; $i--) {
            $periods[] = $month->subMonthsNoOverflow($i)->format('Y-m');
        }

        return $periods;
    }
}
