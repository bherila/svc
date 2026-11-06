<?php

namespace App\Services\Accounting;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\Accounting\AccountingFeed;
use App\Support\Accounting\AccountingFeedQuery;
use App\Support\Accounting\FeedBounds;
use App\Support\Accounting\FeedEntry;
use App\Support\Accounting\FeedPosition;
use App\Support\Accounting\IncomeYear;
use App\Support\WorkspaceClock;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The accounting reads behind `finance.invoice_lines.list`,
 * `finance.cash_income.list` and `finance.income_export.get` (docs/ux-overhaul.md
 * section 13), for workspace owners and admins only: an invoice line entry, a
 * cash entry or a yearly figure is the workspace's books.
 *
 * Every read is scoped to the workspace and computed in the request; nothing
 * is stored and no queue worker is involved. REST and MCP reach this one
 * implementation.
 */
final class AccountingReadService
{
    /** Entries read per page while a year is summed: a bound on memory, not a limit on the year. */
    private const int EXPORT_PAGE = 1000;

    public function __construct(
        private readonly AgentAccess $access,
        private readonly AccountingFeedRunner $feeds,
        private readonly InvoiceLineFeedSources $lines,
        private readonly CashIncomeFeedSources $cash,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, watermark: string}}
     *
     * @throws AuthorizationException
     */
    public function invoiceLines(User|AgentPrincipal $user, Workspace $workspace, AccountingFeedQuery $query): array
    {
        $this->authorize($user, $workspace);

        return $this->feeds->run($workspace, $query, $this->lines->sources($workspace, $query, $this->companyId($workspace, $query)));
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, watermark: string}}
     *
     * @throws AuthorizationException
     */
    public function cashIncome(User|AgentPrincipal $user, Workspace $workspace, AccountingFeedQuery $query): array
    {
        $this->authorize($user, $workspace);

        return $this->feeds->run($workspace, $query, $this->cash->sources($workspace, $query, $this->companyId($workspace, $query)));
    }

    /**
     * One calendar year of the workspace's income by month, summed from the
     * entries of the two feeds so it cannot disagree with them. Bounded by
     * the invoices, corrections, voids, payments and movements dated in the
     * year.
     *
     * @return array{year: int, months: list<array<string, int|string>>, totals: array<string, int>, currency: string, assumptions: array<string, mixed>}
     *
     * @throws AuthorizationException
     */
    public function incomeYear(User|AgentPrincipal $user, Workspace $workspace, int $year): array
    {
        $this->authorize($user, $workspace);
        $dates = ['from' => sprintf('%04d-01-01', $year), 'to' => sprintf('%04d-12-31', $year)];
        $income = new IncomeYear($year);
        $everything = new FeedBounds(null, FeedBounds::END);

        $invoiced = AccountingFeedQuery::fromInput(AccountingFeed::InvoiceLines, $dates);
        $this->sum($this->lines->sources($workspace, $invoiced, null), $everything, $income->addLine(...));
        $received = AccountingFeedQuery::fromInput(AccountingFeed::CashIncome, $dates);
        $this->sum($this->cash->sources($workspace, $received, null), $everything, $income->addCash(...));

        return [
            ...$income->toArray(),
            'currency' => (string) $workspace->default_currency,
            'assumptions' => [
                'as_of' => $this->clock->today($workspace)->toDateString(),
                'timezone' => $workspace->timezone,
                'invoiced_placement' => 'An invoice line by its invoice\'s issue date; a correction, a void and a recorded refund by the day it was recorded in the workspace calendar.',
                'cash_placement' => 'A payment by its received date; a processor refund, dispute or fee by its occurrence date.',
                'method_version' => IncomeYear::METHOD_VERSION,
            ],
        ];
    }

    /**
     * Feed every entry of the sources through `$add`, a page at a time.
     *
     * @param  list<Closure(FeedBounds, ?FeedPosition, int): list<FeedEntry>>  $sources
     * @param  Closure(array<string, mixed>): void  $add
     */
    private function sum(array $sources, FeedBounds $bounds, Closure $add): void
    {
        $after = null;
        do {
            [$page, $more] = $this->feeds->page($sources, $bounds, $after, self::EXPORT_PAGE);
            foreach ($page as $entry) {
                $add($entry->payload);
            }
            $after = $page === [] ? null : $page[array_key_last($page)]->position;
        } while ($more);
    }

    /** @throws AuthorizationException */
    private function authorize(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw new AuthorizationException('Accounting reads require a workspace owner or admin.');
        }
    }

    /** The internal id of the client the query names, which must belong to this workspace. */
    private function companyId(Workspace $workspace, AccountingFeedQuery $query): ?int
    {
        if ($query->clientId === null) {
            return null;
        }

        return (int) ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $query->clientId)
            ->firstOrFail()
            ->getKey();
    }
}
