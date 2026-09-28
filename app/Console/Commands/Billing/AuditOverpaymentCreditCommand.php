<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\OverpaymentCreditAuditor;
use App\Support\Billing\CreditPoolPartition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report overpayment credit funded against credit consumed, per pool.
 *
 * A printer over {@see OverpaymentCreditAuditor}. By default it prints counts
 * and per-currency totals only - no id, company or workspace - so the output
 * is safe to paste into an issue, like the other billing audits. `--list` adds
 * one row per pool keyed by the workspace's and company's public ids, for the
 * operator who has to find them; that output is not for pasting.
 *
 * Read-only. A deficit is a current state to investigate, not proof of how it
 * arose, and nothing here repairs data or refunds anyone.
 */
final class AuditOverpaymentCreditCommand extends Command
{
    protected $signature = 'svc:billing:audit-overpayment-credit
        {--format=text : Output text or json}
        {--list : Also list each pool by public id (not safe to paste)}';

    protected $description = 'Compare overpayment credit funded with credit consumed, per workspace, company and currency';

    public function handle(OverpaymentCreditAuditor $auditor): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $partitions = $auditor->partitions();
        $summary = $this->summary($partitions);
        $rows = $this->option('list') ? $this->rows($partitions) : null;

        if ($format === 'json') {
            $this->line((string) json_encode(
                array_filter(['summary' => $summary, 'pools' => $rows], fn ($value): bool => $value !== null),
                JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Credit pools with any funding, spending or problem', (string) $summary['pools']);
        $this->components->twoColumnDetail('... evaluable', (string) $summary['evaluable']);
        $this->components->twoColumnDetail('... in deficit', (string) $summary['in_deficit']);
        $this->components->twoColumnDetail('... unevaluable', (string) $summary['unevaluable']);
        foreach ($summary['deficit_minor_by_currency'] as $currency => $minor) {
            $this->components->twoColumnDetail('Deficit, summed per pool ('.$currency.', minor units)', (string) $minor);
        }
        foreach ($summary['unevaluable_reasons'] as $reason => $count) {
            $this->components->twoColumnDetail('Unevaluable: '.$reason, (string) $count);
        }
        $this->newLine();

        if ($summary['in_deficit'] === 0) {
            $this->components->info('No evaluable pool has spent more credit than it was funded with.');
        } else {
            $this->components->warn(
                $summary['in_deficit'].' pool(s) have spent more credit than they were funded with. That is a current deficit to investigate, not proof of how it arose. Nothing has been changed.'
            );
        }
        if ($summary['unevaluable'] > 0) {
            $this->components->warn(
                $summary['unevaluable'].' pool(s) hold data the credit ledger cannot read, so no balance is stated for them. They are not zero.'
            );
        }

        if ($rows !== null) {
            $this->newLine();
            $this->table(
                ['Workspace', 'Company', 'Currency', 'Funded', 'Consumed', 'Difference', 'Deficit', 'Problems'],
                array_map(fn (array $row): array => [
                    $row['workspace'], $row['company'] ?? '-', $row['currency'],
                    $row['funded_minor'] ?? 'n/a', $row['consumed_minor'] ?? 'n/a',
                    $row['difference_minor'] ?? 'n/a', $row['deficit_minor'] ?? 'n/a',
                    json_encode($row['problems'], JSON_THROW_ON_ERROR),
                ], $rows),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<CreditPoolPartition>  $partitions
     * @return array{pools: int, evaluable: int, in_deficit: int, unevaluable: int, deficit_minor_by_currency: array<string, int>, unevaluable_reasons: array<string, int>}
     */
    private function summary(array $partitions): array
    {
        $deficits = [];
        $reasons = [];
        $inDeficit = 0;
        $unevaluable = 0;
        foreach ($partitions as $partition) {
            if (! $partition->evaluable()) {
                $unevaluable++;
                foreach ($partition->problems() as $reason => $count) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + $count;
                }

                continue;
            }
            $deficit = (int) $partition->deficit();
            if ($deficit > 0) {
                $inDeficit++;
                // Summed per pool, each already floored at zero: a surplus in
                // one company never hides another's deficit.
                $deficits[$partition->currency] = ($deficits[$partition->currency] ?? 0) + $deficit;
            }
        }
        ksort($deficits);
        ksort($reasons);

        return [
            'pools' => count($partitions),
            'evaluable' => count($partitions) - $unevaluable,
            'in_deficit' => $inDeficit,
            'unevaluable' => $unevaluable,
            'deficit_minor_by_currency' => $deficits,
            'unevaluable_reasons' => $reasons,
        ];
    }

    /**
     * @param  list<CreditPoolPartition>  $partitions
     * @return list<array{workspace: string, company: string|null, currency: string, funded_minor: int|null, consumed_minor: int|null, difference_minor: int|null, deficit_minor: int|null, problems: array<string, int>}>
     */
    private function rows(array $partitions): array
    {
        $workspaces = DB::table('workspaces')->pluck('public_id', 'id')->all();
        $companies = DB::table('client_companies')->pluck('public_id', 'id')->all();

        return array_map(fn (CreditPoolPartition $partition): array => [
            'workspace' => (string) ($workspaces[$partition->workspaceId] ?? 'missing'),
            'company' => $partition->companyId === null ? null : (string) ($companies[$partition->companyId] ?? 'missing'),
            'currency' => $partition->currency,
            'funded_minor' => $partition->funded(),
            'consumed_minor' => $partition->consumed(),
            'difference_minor' => $partition->difference(),
            'deficit_minor' => $partition->deficit(),
            'problems' => $partition->problems(),
        ], $partitions);
    }
}
