<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\BillingCadence;
use App\Support\Billing\CadenceOverageLineDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CadenceOverageLineDescriptionTest extends TestCase
{
    #[DataProvider('descriptions')]
    public function test_it_formats_each_cadence_with_the_generators_exact_description(
        BillingCadence $cadence,
        string $expected,
    ): void {
        $this->assertSame($expected, CadenceOverageLineDescription::for($cadence));
    }

    /** @return iterable<string, array{BillingCadence, string}> */
    public static function descriptions(): iterable
    {
        yield 'monthly' => [
            BillingCadence::Monthly,
            'Catch-up hours for prior month overage and minimum availability',
        ];
        yield 'quarterly' => [BillingCadence::Quarterly, 'Additional hours beyond cadence retainer'];
        yield 'semiannual' => [BillingCadence::SemiAnnual, 'Additional hours beyond cadence retainer'];
        yield 'annual' => [BillingCadence::Annual, 'Additional hours beyond cadence retainer'];
    }
}
