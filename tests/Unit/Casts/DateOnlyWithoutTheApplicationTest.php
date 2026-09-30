<?php

namespace Tests\Unit\Casts;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * A calendar date can be set on a model built with no application at all (#362).
 *
 * Several unit tests build agreements and invoices without Laravel booted, and
 * the date cast they relied on did not reach for the database connection. The
 * cast that replaced it must not either.
 */
final class DateOnlyWithoutTheApplicationTest extends TestCase
{
    public function test_a_date_is_set_and_read_without_a_connection(): void
    {
        $agreement = new ClientAgreement;
        $agreement->starts_on = CarbonImmutable::parse('2026-01-31 13:00:00');
        $invoice = new ClientInvoice;
        $invoice->service_period_end = '2026-02-28';

        $this->assertSame('2026-01-31', $agreement->getAttributes()['starts_on']);
        $this->assertSame('2026-02-28', $invoice->service_period_end?->toDateString());
    }
}
