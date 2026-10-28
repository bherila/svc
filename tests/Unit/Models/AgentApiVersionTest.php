<?php

namespace Tests\Unit\Models;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Tests\TestCase;

final class AgentApiVersionTest extends TestCase
{
    public function test_unrelated_models_keep_their_row_revision_token(): void
    {
        config(['app.key' => 'synthetic-version-key']);
        $invoice = (new ClientInvoice)->setRawAttributes(['id' => 8, 'lock_version' => 3]);
        $expected = hash_hmac('sha256', 'ClientInvoice:8:3', 'synthetic-version-key');
        $this->assertSame($expected, AgentApiVersion::for($invoice));
        $this->assertTrue(AgentApiVersion::matches($invoice, $expected));
        $this->assertFalse(AgentApiVersion::matches($invoice, str_repeat('0', 64)));
        $invoice->setRawAttributes(['id' => 8]);
        $this->assertSame(hash_hmac('sha256', 'ClientInvoice:8:1', 'synthetic-version-key'), AgentApiVersion::for($invoice));
    }

    public function test_time_versions_discriminate_revision_invoice_and_allocation_state_without_queries(): void
    {
        config(['app.key' => 'synthetic-version-key']);
        $entry = (new ClientTimeEntry)->setRawAttributes(['id' => 12, 'workspace_id' => 42, 'client_company_id' => 7, 'lock_version' => 3])
            ->setRelation('invoiceLines', collect());
        $unallocated = AgentApiVersion::for($entry);
        $this->assertSame(hash_hmac('sha256', 'ClientTimeEntry:12:3::unallocated', 'synthetic-version-key'), $unallocated);
        $invoice = (new ClientInvoice)->setRawAttributes(['id' => 8, 'public_id' => '00000000-0000-4000-8000-000000000008', 'workspace_id' => 42, 'client_company_id' => 7, 'status' => 'draft']);
        $line = (new ClientInvoiceLine)->setRawAttributes(['workspace_id' => 42])
            ->setRelation('pivot', (new Pivot)->setRawAttributes(['workspace_id' => 42]))
            ->setRelation('invoice', $invoice);
        $entry->setRelation('invoiceLines', collect([$line]));
        $reserved = AgentApiVersion::for($entry);
        $this->assertSame(hash_hmac('sha256', 'ClientTimeEntry:12:3:00000000-0000-4000-8000-000000000008:reserved', 'synthetic-version-key'), $reserved);
        $this->assertNotSame($unallocated, $reserved);
        $this->assertFalse(AgentApiVersion::matches($entry, $unallocated));
        $this->assertTrue(AgentApiVersion::matches($entry, $reserved));
        $invoice->status = 'issued';
        $consumed = AgentApiVersion::for($entry);
        $this->assertNotSame($reserved, $consumed);
        $invoice->public_id = '00000000-0000-4000-8000-000000000009';
        $changedInvoice = AgentApiVersion::for($entry);
        $this->assertNotSame($consumed, $changedInvoice);
        $entry->setAttribute('lock_version', 4);
        $this->assertNotSame($changedInvoice, AgentApiVersion::for($entry));
        $entry->setAttribute('lock_version', 3)->setRelation('invoiceLines', collect());
        $this->assertSame($unallocated, AgentApiVersion::for($entry));
    }
}
