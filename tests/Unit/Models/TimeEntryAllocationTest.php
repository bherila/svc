<?php

namespace Tests\Unit\Models;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Support\AgentApi\TimeEntryAllocation;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TimeEntryAllocationTest extends TestCase
{
    public function test_scoped_relationships_are_required_without_lazy_queries(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Load scoped time-entry allocations');
        TimeEntryAllocation::fromEntry($this->entry());
    }

    public function test_invoice_relationship_is_required_without_lazy_queries(): void
    {
        $line = $this->line();
        $line->unsetRelation('invoice');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Load allocation invoices');
        TimeEntryAllocation::fromEntry($this->entry()->setRelation('invoiceLines', collect([$line])));
    }

    public function test_empty_allocation_is_unallocated(): void
    {
        $result = TimeEntryAllocation::fromEntry($this->entry()->setRelation('invoiceLines', collect()));
        $this->assertNull($result->invoiceId);
        $this->assertSame('unallocated', $result->state);
    }

    public static function invoiceStates(): iterable
    {
        yield 'draft' => ['draft', 'reserved'];
        yield 'issued' => ['issued', 'consumed'];
        yield 'paid' => ['paid', 'consumed'];
        yield 'void' => ['void', 'consumed'];
        yield 'unknown' => ['future_status', 'consumed'];
    }

    #[DataProvider('invoiceStates')]
    public function test_classification_uses_invoice_status_even_when_entry_stays_approved(string $status, string $state): void
    {
        $line = $this->line();
        $line->invoice->setRawAttributes(['id' => 8, 'public_id' => '00000000-0000-4000-8000-000000000008', 'workspace_id' => 42, 'client_company_id' => 7, 'status' => $status]);
        $result = TimeEntryAllocation::fromEntry($this->entry()->setRelation('invoiceLines', collect([$line])));
        $this->assertSame('00000000-0000-4000-8000-000000000008', $result->invoiceId);
        $this->assertSame($state, $result->state);
    }

    public static function foreignParts(): iterable
    {
        yield 'line workspace' => ['line'];
        yield 'pivot workspace' => ['pivot'];
        yield 'invoice workspace' => ['invoice'];
        yield 'invoice company' => ['company'];
        yield 'missing invoice' => ['missing'];
        yield 'missing pivot' => ['missing-pivot'];
    }

    #[DataProvider('foreignParts')]
    public function test_a_malformed_allocation_never_reveals_an_invoice(string $part): void
    {
        $line = $this->line();
        match ($part) {
            'line' => $line->workspace_id = 99,
            'pivot' => $line->pivot->workspace_id = 99,
            'invoice' => $line->invoice->workspace_id = 99,
            'company' => $line->invoice->client_company_id = 99,
            'missing' => $line->setRelation('invoice', null),
            'missing-pivot' => $line->unsetRelation('pivot'),
        };
        $result = TimeEntryAllocation::fromEntry($this->entry()->setRelation('invoiceLines', collect([$line])));
        $this->assertNull($result->invoiceId);
        $this->assertSame('unallocated', $result->state);
    }

    public function test_consumed_precedes_reserved_and_lowest_invoice_id_is_stable(): void
    {
        $draft = $this->line();
        $paid = $this->line();
        $paid->invoice->id = 9;
        $paid->invoice->public_id = '00000000-0000-4000-8000-000000000009';
        $paid->invoice->status = 'paid';
        $issued = $this->line();
        $issued->invoice->id = 4;
        $issued->invoice->public_id = '00000000-0000-4000-8000-000000000004';
        $issued->invoice->status = 'issued';
        $result = TimeEntryAllocation::fromEntry($this->entry()->setRelation('invoiceLines', collect([$draft, $paid, $issued])));
        $this->assertSame('consumed', $result->state);
        $this->assertSame('00000000-0000-4000-8000-000000000004', $result->invoiceId);
    }

    private function entry(): ClientTimeEntry
    {
        return (new ClientTimeEntry)->setRawAttributes(['workspace_id' => 42, 'client_company_id' => 7, 'status' => 'approved']);
    }

    private function line(): ClientInvoiceLine
    {
        $invoice = (new ClientInvoice)->setRawAttributes(['id' => 8, 'public_id' => '00000000-0000-4000-8000-000000000008', 'workspace_id' => 42, 'client_company_id' => 7, 'status' => 'draft']);
        $pivot = (new Pivot)->setRawAttributes(['workspace_id' => 42]);

        return (new ClientInvoiceLine)->setRawAttributes(['workspace_id' => 42])->setRelation('pivot', $pivot)->setRelation('invoice', $invoice);
    }
}
