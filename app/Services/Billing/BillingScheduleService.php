<?php

namespace App\Services\Billing;

use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Support\Concurrency\Locks;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BillingScheduleService
{
    public function __construct(private readonly InvoiceLifecycleService $invoices) {}

    /** @return list<ClientInvoice> */
    public function generateDue(ClientBillingSchedule $schedule, CarbonImmutable $through): array
    {
        return DB::transaction(function () use ($schedule, $through): array {
            $locked = ClientBillingSchedule::query()->whereKey($schedule->id)->tap(Locks::forUpdate())->firstOrFail();
            if (! $locked->is_active) {
                return [];
            }

            $created = [];
            $nextRun = CarbonImmutable::parse((string) $locked->next_run_on);
            $template = $this->normalizedTemplate($locked->getAttribute('line_template'));
            while ($nextRun->lte($through)) {
                [$start, $end, $next] = $this->period($nextRun, (string) $locked->cadence);
                // Matched on the tenant and the period first, and on the
                // schedule link only to decide whose invoice it is.
                //
                // `client_billing_schedule_id` is legitimately null - a cadence
                // invoice created through `ClientInvoicingService` never sets
                // it, and an ad-hoc one has no schedule to name - so asking
                // `where('client_billing_schedule_id', $locked->id)` alone made
                // an unlinked invoice for exactly this period invisible: SQL
                // compares a null to a value as UNKNOWN, the schedule concluded
                // the period was unbilled, and raised - and issued - a second
                // invoice for it. The unique index does not save this either,
                // because a unique index does not constrain a null.
                //
                // So an invoice that does not say which schedule made it now
                // blocks, which is the fail-closed reading. An invoice that
                // names a *different* schedule does not: that is another
                // schedule's period and blocking on it would lose revenue
                // rather than protect it.
                $invoice = ClientInvoice::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('client_company_id', $locked->client_company_id)
                    ->whereDate('service_period_start', $start)
                    ->whereDate('service_period_end', $end)
                    ->where(function (Builder $mineOrUnclaimed) use ($locked): void {
                        $mineOrUnclaimed
                            ->where('client_billing_schedule_id', $locked->id)
                            ->orWhereNull('client_billing_schedule_id');
                    })
                    ->first();

                if ($invoice === null) {
                    $draft = $this->invoices->createDraft(
                        $locked->workspace,
                        $locked->clientCompany,
                        [
                            'invoice_number' => $this->invoiceNumber($locked, $start),
                            'issue_date' => $start->toDateString(),
                            'due_date' => $start->addDays((int) $locked->due_days)->toDateString(),
                            'service_period_start' => $start->toDateString(),
                            'service_period_end' => $end->toDateString(),
                            'currency' => $locked->currency,
                            'client_agreement_id' => $locked->client_agreement_id,
                            'client_billing_schedule_id' => $locked->id,
                        ],
                        $template,
                    );
                    $created[] = $this->invoices->issue($draft, $locked->workspace);
                } else {
                    $created[] = $invoice;
                }

                $nextRun = $next;
                $locked->forceFill(['next_run_on' => $nextRun->toDateString()])->save();
            }

            return $created;
        });
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:CarbonImmutable} */
    private function period(CarbonImmutable $start, string $cadence): array
    {
        $months = match ($cadence) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            default => throw new DomainException('Unsupported billing cadence.'),
        };
        $next = $start->addMonthsNoOverflow($months);

        return [$start, $next->subDay(), $next];
    }

    private function invoiceNumber(ClientBillingSchedule $schedule, CarbonImmutable $start): string
    {
        $base = 'INV-'.$start->format('Ymd').'-'.strtoupper(substr(str_replace('-', '', $schedule->public_id), 0, 8));
        $number = $base;
        $suffix = 2;
        while (ClientInvoice::query()->where('workspace_id', $schedule->workspace_id)->where('invoice_number', $number)->exists()) {
            $number = $base.'-'.$suffix++;
        }

        return $number;
    }

    /** @return list<array<string, mixed>> */
    private function normalizedTemplate(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('A billing schedule line template must be an array.');
        }

        $template = [];
        foreach (array_values($value) as $line) {
            if (! is_array($line)) {
                throw new DomainException('A billing schedule line template entry must be an object.');
            }
            $template[] = $line;
        }

        return $template;
    }
}
