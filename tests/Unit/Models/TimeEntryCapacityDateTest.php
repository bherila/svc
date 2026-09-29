<?php

namespace Tests\Unit\Models;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;
use Tests\TestCase;

/**
 * Which month's pool an hour of work drew on, decided without a database.
 *
 * The diff-scoped mutation lane runs the Unit suite alone, so the placement
 * rule and the tenant scoping of the eager load it depends on are pinned here
 * rather than only through the feature ledger tests.
 */
final class TimeEntryCapacityDateTest extends TestCase
{
    public function test_ordinary_work_draws_on_the_day_it_was_worked_without_reading_any_invoice(): void
    {
        // No relation loaded: ordinary work must never need one.
        $entry = $this->entry(deferred: false);

        $this->assertSame('2026-01-20', $entry->capacityDate()->toDateString());
    }

    public function test_deferred_work_nothing_has_applied_draws_on_nothing_later(): void
    {
        $entry = $this->entry(deferred: true)->setRelation('invoiceLines', collect());

        $this->assertSame('2026-01-20', $entry->capacityDate()->toDateString());
    }

    public function test_applied_deferred_work_draws_on_the_absorbing_lines_date(): void
    {
        $line = (new ClientInvoiceLine)->setRawAttributes(['line_date' => '2026-03-31']);
        $entry = $this->entry(deferred: true)->setRelation('invoiceLines', collect([$line]));

        $this->assertSame('2026-03-31', $entry->capacityDate()->toDateString());
    }

    public function test_the_lines_own_date_outranks_its_invoices_work_period_end(): void
    {
        $invoice = (new ClientInvoice)->setRawAttributes(['service_period_end' => '2026-04-30']);
        $line = (new ClientInvoiceLine)->setRawAttributes(['line_date' => '2026-03-31'])->setRelation('invoice', $invoice);
        $entry = $this->entry(deferred: true)->setRelation('invoiceLines', collect([$line]));

        $this->assertSame('2026-03-31', $entry->capacityDate()->toDateString());
    }

    public function test_an_undated_line_falls_back_to_its_invoices_work_period_end(): void
    {
        $invoice = (new ClientInvoice)->setRawAttributes(['service_period_end' => '2026-04-30']);
        $line = (new ClientInvoiceLine)->setRawAttributes(['line_date' => null])->setRelation('invoice', $invoice);
        $entry = $this->entry(deferred: true)->setRelation('invoiceLines', collect([$line]));

        $this->assertSame('2026-04-30', $entry->capacityDate()->toDateString());
    }

    public function test_a_line_dated_before_the_work_never_moves_it_earlier(): void
    {
        $line = (new ClientInvoiceLine)->setRawAttributes(['line_date' => '2025-12-31']);
        $entry = $this->entry(deferred: true)->setRelation('invoiceLines', collect([$line]));

        $this->assertSame('2026-01-20', $entry->capacityDate()->toDateString());
    }

    public function test_deferred_work_refuses_to_be_placed_without_the_scoped_eager_load(): void
    {
        $this->expectException(LogicException::class);

        $this->entry(deferred: true)->capacityDate();
    }

    /**
     * The eager load names the absorbing line and its invoice, each bound to
     * the caller's workspace - line, pivot and invoice alike.
     */
    public function test_the_placement_eager_load_is_bound_to_one_workspace_at_every_hop(): void
    {
        $eagerLoads = ClientTimeEntry::query()->withCapacityPlacement(42)->getEagerLoads();
        $this->assertArrayHasKey('invoiceLines', $eagerLoads);

        /** @var BelongsToMany<ClientInvoiceLine, ClientTimeEntry> $lines */
        $lines = (new ClientTimeEntry)->invoiceLines();
        $eagerLoads['invoiceLines']($lines);
        // Read off the builder's where clauses, not its SQL text: identifier
        // quoting differs between SQLite and MariaDB.
        $this->assertContains(['client_invoice_lines.workspace_id', 42], $this->equalities($lines->getQuery()->getQuery()->wheres));
        $this->assertContains(['client_invoice_line_time_entries.workspace_id', 42], $this->equalities($lines->getQuery()->getQuery()->wheres));

        $nested = $lines->getQuery()->getEagerLoads();
        $this->assertArrayHasKey('invoice', $nested);
        $invoice = (new ClientInvoiceLine)->invoice();
        $nested['invoice']($invoice);
        $this->assertContains(['client_invoices.workspace_id', 42], $this->equalities($invoice->getQuery()->getQuery()->wheres));
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     * @return list<array{mixed, mixed}>
     */
    private function equalities(array $wheres): array
    {
        return array_values(array_map(
            static fn (array $where): array => [$where['column'] ?? null, $where['value'] ?? null],
            array_filter($wheres, static fn (array $where): bool => ($where['type'] ?? null) === 'Basic' && ($where['operator'] ?? null) === '='),
        ));
    }

    public function test_only_deferred_work_applied_to_a_retainer_line_draws_as_deferred(): void
    {
        $retainerLine = (new ClientInvoiceLine)->setRawAttributes(['type' => 'prior_month_retainer', 'line_date' => '2026-03-31']);
        $terminationLine = (new ClientInvoiceLine)->setRawAttributes(['type' => 'additional_hours', 'line_date' => '2026-03-31']);

        // Ordinary work never needs its line to answer.
        $this->assertFalse($this->entry(deferred: false)->drawsAsDeferred());
        $this->assertTrue($this->entry(deferred: true)->setRelation('invoiceLines', collect([$retainerLine]))->drawsAsDeferred());
        $this->assertFalse($this->entry(deferred: true)->setRelation('invoiceLines', collect([$terminationLine]))->drawsAsDeferred(), 'Billed at rate on termination');
        $this->assertFalse($this->entry(deferred: true)->setRelation('invoiceLines', collect())->drawsAsDeferred());

        $this->expectException(LogicException::class);
        $this->entry(deferred: true)->drawsAsDeferred();
    }

    private function entry(bool $deferred): ClientTimeEntry
    {
        return (new ClientTimeEntry)->setRawAttributes(['worked_on' => '2026-01-20', 'is_deferred' => $deferred ? 1 : 0]);
    }
}
