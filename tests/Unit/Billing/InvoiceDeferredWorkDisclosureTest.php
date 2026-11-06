<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoiceDeferredWorkDisclosure;
use App\Support\Billing\InvoiceDeferredWorkSnapshot;
use App\Support\Billing\InvoiceLineDetail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceDeferredWorkDisclosureTest extends TestCase
{
    private static function snapshot(): InvoiceDeferredWorkSnapshot
    {
        $snapshot = InvoiceDeferredWorkSnapshot::fromArray(['version' => 2, 'entries' => [
            ['id' => 1, 'workedOn' => '2026-06-01', 'minutes' => 90, 'description' => 'Synthetic deferred work', 'projectId' => 4],
        ]]);
        self::assertNotNull($snapshot);

        return $snapshot;
    }

    /** @return iterable<string, array{string}> */
    public static function audiences(): iterable
    {
        yield 'operator' => [InvoiceLineDetail::OPERATOR];
        yield 'client' => [InvoiceLineDetail::CLIENT];
    }

    #[DataProvider('audiences')]
    public function test_no_stored_snapshot_discloses_nothing_and_asks_nothing(string $audience): void
    {
        $asked = false;

        self::assertNull(InvoiceDeferredWorkDisclosure::forAudience(null, $audience, function () use (&$asked): bool {
            $asked = true;

            return true;
        }));
        self::assertFalse($asked);
    }

    public function test_the_operator_copy_always_shows_the_list_without_asking(): void
    {
        $asked = false;

        self::assertSame(self::snapshot()->documentData(), InvoiceDeferredWorkDisclosure::forAudience(
            self::snapshot(),
            InvoiceLineDetail::OPERATOR,
            function () use (&$asked): bool {
                $asked = true;

                return false;
            },
        ));
        self::assertFalse($asked);
    }

    public function test_the_client_copy_shows_the_list_only_when_the_agreement_opts_in(): void
    {
        self::assertSame(
            self::snapshot()->documentData(),
            InvoiceDeferredWorkDisclosure::forAudience(self::snapshot(), InvoiceLineDetail::CLIENT, static fn (): bool => true),
        );
        self::assertNull(
            InvoiceDeferredWorkDisclosure::forAudience(self::snapshot(), InvoiceLineDetail::CLIENT, static fn (): bool => false),
        );
    }
}
