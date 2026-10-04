<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\BillingScheduleLineTemplate;
use App\Support\Billing\InvoiceLineType;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The line types only generation writes, as the shared template reader sees them. */
final class BillingScheduleLineTemplateTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function systemOnly(): iterable
    {
        foreach (InvoiceLineType::systemOnlyValues() as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('systemOnly')]
    public function test_a_line_with_a_system_only_type_is_refused(string $type): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            "A billing schedule line template cannot use the {$type} line type, which only invoice generation writes; "
            .'change that line to an ordinary type.',
        );

        BillingScheduleLineTemplate::normalize([
            ['type' => 'service', 'description' => 'Support', 'unit_amount' => 100],
            ['type' => $type, 'description' => 'Retainer', 'unit_amount' => 100],
        ]);
    }

    public function test_ordinary_and_untyped_lines_pass_through_unchanged(): void
    {
        $lines = [
            ['type' => 'service', 'description' => 'Support', 'unit_amount' => 100],
            ['description' => 'No type yet', 'unit_amount' => 100],
        ];

        $this->assertSame($lines, BillingScheduleLineTemplate::normalize($lines));
    }
}
