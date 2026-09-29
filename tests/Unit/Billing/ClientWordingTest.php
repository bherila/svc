<?php

namespace Tests\Unit\Billing;

use App\Models\ClientTimeEntry;
use App\Support\Billing\InvoiceLineDetail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a client reads for one time entry, without a database. */
final class ClientWordingTest extends TestCase
{
    /** @return iterable<string, array{bool, string|null, string}> */
    public static function entries(): iterable
    {
        yield 'visible with wording' => [true, 'Integration support', 'Integration support'];
        yield 'visible with padded wording, stored trimmed' => [true, "  Integration support\n\n", 'Integration support'];
        yield 'visible with blank wording' => [true, "  \n", InvoiceLineDetail::CLIENT_GENERIC_LABEL];
        yield 'visible with no wording' => [true, null, InvoiceLineDetail::CLIENT_GENERIC_LABEL];
        yield 'not visible, wording ignored' => [false, 'Never shown', InvoiceLineDetail::CLIENT_GENERIC_LABEL];
    }

    #[DataProvider('entries')]
    public function test_the_client_reads_its_own_wording_or_the_neutral_label(bool $visible, ?string $wording, string $expected): void
    {
        $entry = new ClientTimeEntry;
        $entry->forceFill([
            'description' => 'Internal note',
            'is_visible_to_client' => $visible,
            'client_visible_description' => $wording,
        ]);

        $this->assertSame($expected, InvoiceLineDetail::clientWording($entry));
    }
}
