<?php

namespace Tests\Unit\Models;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use DateTimeInterface;
use Tests\TestCase;

/**
 * The diff-scoped mutation lane runs the Unit suite alone. Keep the existing
 * casts beside the delivery casts they now share an array with so adding an
 * automatic-delivery field cannot silently turn established values back into
 * raw database strings.
 */
final class InvoiceDeliveryModelCastsTest extends TestCase
{
    public function test_client_activation_remains_a_boolean(): void
    {
        $company = (new ClientCompany)->setRawAttributes(['is_active' => '0']);

        $this->assertFalse($company->is_active);
        $this->assertSame('boolean', $company->getCasts()['is_active'] ?? null);
    }

    public function test_invoice_issue_date_remains_a_date(): void
    {
        $invoice = (new ClientInvoice)->setRawAttributes(['issue_date' => '2026-09-15']);

        $this->assertInstanceOf(DateTimeInterface::class, $invoice->issue_date);
        $this->assertSame('date', $invoice->getCasts()['issue_date'] ?? null);
    }
}
