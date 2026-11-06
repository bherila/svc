<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\InvoiceDocumentService;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\Support\Billing\BladeInvoiceDocumentFacts;
use Tests\Support\Billing\InvoiceDocumentFacts;
use Tests\TestCase;

/**
 * Golden tests for the hours statement on the invoice document.
 *
 * Every section and every row `InvoiceHoursStatementRows` can produce has a
 * fixture here, for an ordinary invoice and for a correction, and each is read
 * for both audiences: the statement is stored facts about hours and is the
 * same for an operator and a client. Rows are `[label, value, kind]` in print
 * order, so a changed label, figure, sign, order or indent fails.
 */
class InvoiceDocumentHoursStatementGoldenTest extends TestCase
{
    use BuildsGoldenInvoices;
    use RefreshDatabase;

    private const ORDINARY_INTRO = 'For the work this invoice reconciles and the retainer period that follows it. All figures are hours.';

    private const CORRECTION_INTRO = 'For the work this correction reconciles and the pool it draws on. All figures are hours.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
    }

    protected function documentFacts(): InvoiceDocumentFacts
    {
        return new BladeInvoiceDocumentFacts(app(InvoiceDocumentService::class));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, list<array{string, list<array{string, string, string}>}>}>
     */
    public static function scenarios(): iterable
    {
        yield 'ordinary: core rows only, a quiet month' => [
            ['openingRetainerHours' => 10.0, 'ordinaryHours' => 8.0, 'ordinaryAppliedToWorkPool' => 8.0, 'rolledForwardHours' => 2.0, 'nextRetainerHours' => 10.0],
            self::ORDINARY_INTRO,
            [
                ['Opening pool: August 2026', [
                    ['Retainer hours for August 2026', '10.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '0.00', 'row'],
                    ['Net hours available at the start of August 2026', '10.00', 'total'],
                ]],
                ['Work reconciled on this invoice', [
                    ['Hours worked in August 2026', '8.00', 'row'],
                    ['Applied to the August 2026 pool', '8.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Total catch-up billed on this invoice', '0.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Unused hours rolling into September 2026', '2.00', 'row'],
                    ['Unused hours expiring at the start of September 2026', '0.00', 'row'],
                    ['Hours still owed, carried into September 2026', '0.00', 'row'],
                ]],
                ['Closing position: September 2026', [
                    ['Retainer hours for September 2026', '10.00', 'row'],
                    ['Unused hours rolled in', '2.00', 'row'],
                    ['Hours owed carried in', '0.00', 'row'],
                    ['Net hours available at the start of September 2026', '12.00', 'total'],
                ]],
            ],
        ];

        yield 'ordinary: every optional row, a quarter' => [
            [
                'cadence' => 'quarterly', 'workStart' => '2026-07-01', 'workEnd' => '2026-09-30',
                'retainerStart' => '2026-10-01', 'retainerEnd' => '2026-12-31',
                'openingRetainerHours' => 30.0, 'openingRolloverHours' => 3.0, 'openingDeficitHours' => 1.5,
                'openingExpiredHours' => 2.0, 'expiredWithinPeriodHours' => 1.0,
                'ordinaryHours' => 50.0, 'ordinaryAppliedToWorkPool' => 25.0, 'ordinaryAppliedToNextRetainer' => 4.0,
                'ordinaryBilledAtRate' => 21.0, 'subcontractorHours' => 2.5,
                'deferredAppliedHours' => 3.0, 'deferredAppliedEntries' => 2, 'recarriedSettledHours' => 1.25,
                'deferredBilledOnTerminationHours' => 4.0, 'catchUpBilledHours' => 25.0,
                'minimumAvailabilityHours' => 4.0, 'minimumAvailabilityThresholdHours' => 10.0, 'interimBilledHours' => 0.75,
                'rolledForwardHours' => 2.0, 'expiringHours' => 1.0, 'deficitCarriedForwardHours' => 0.5,
                'deferredBacklogHours' => 6.0, 'deferredBacklogEntries' => 3, 'recarriedRemainingHours' => 0.5,
                'nextRetainerHours' => 30.0,
            ],
            self::ORDINARY_INTRO,
            [
                ['Opening pool: July – September 2026', [
                    ['Retainer hours for July – September 2026', '30.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '3.00', 'row'],
                    ['Hours owed from earlier periods', '-1.50', 'row'],
                    ['Net hours available at the start of July – September 2026', '31.50', 'total'],
                    ['Unused hours that expired at the start of July – September 2026', '2.00', 'note'],
                ]],
                ['Work reconciled on this invoice', [
                    ['Hours worked in July – September 2026', '50.00', 'row'],
                    ['Applied to the July – September 2026 pool', '25.00', 'detail'],
                    ['Applied in advance to the October – December 2026 retainer', '4.00', 'detail'],
                    ['Billed at the hourly rate', '21.00', 'detail'],
                    ['Subcontractor hours billed separately at their own rate (not drawn on the pool)', '2.50', 'row'],
                    ['Deferred work applied to free capacity (2 entries)', '3.00', 'row'],
                    ['Earlier deferred work settled from free capacity', '1.25', 'row'],
                    ['Deferred work billed at the hourly rate on termination', '4.00', 'row'],
                    ['Already billed in this cycle by interim invoices', '0.75', 'row'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '21.00', 'row'],
                    ['Minimum availability: restores the agreement\'s 10.00-hour minimum for October – December 2026', '4.00', 'row'],
                    ['Total catch-up billed on this invoice', '25.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Unused hours that expired within July – September 2026', '1.00', 'row'],
                    ['Unused hours rolling into October – December 2026', '2.00', 'row'],
                    ['Unused hours expiring at the start of October – December 2026', '1.00', 'row'],
                    ['Hours still owed, carried into October – December 2026', '0.50', 'row'],
                    ['Deferred work waiting for free capacity (3 entries)', '6.00', 'row'],
                    ['Earlier deferred work still to settle', '0.50', 'row'],
                ]],
                ['Closing position: October – December 2026', [
                    ['Retainer hours for October – December 2026', '30.00', 'row'],
                    ['Unused hours rolled in', '2.00', 'row'],
                    ['Hours owed carried in', '-0.50', 'row'],
                    ['Net hours available at the start of October – December 2026', '31.50', 'total'],
                ]],
            ],
        ];

        yield 'ordinary: one entry reads singular, a part-month period, no next period' => [
            [
                'workStart' => '2026-08-05', 'workEnd' => '2026-08-20', 'retainerStart' => null, 'retainerEnd' => null,
                'openingRetainerHours' => 5.0, 'ordinaryHours' => 3.0, 'ordinaryAppliedToWorkPool' => 3.0,
                'deferredAppliedHours' => 1.0, 'deferredAppliedEntries' => 1,
                'deferredBacklogHours' => 0.5, 'deferredBacklogEntries' => 1, 'nextRetainerHours' => 5.0,
            ],
            self::ORDINARY_INTRO,
            [
                ['Opening pool: Aug 5, 2026 – Aug 20, 2026', [
                    ['Retainer hours for Aug 5, 2026 – Aug 20, 2026', '5.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '0.00', 'row'],
                    ['Net hours available at the start of Aug 5, 2026 – Aug 20, 2026', '5.00', 'total'],
                ]],
                ['Work reconciled on this invoice', [
                    ['Hours worked in Aug 5, 2026 – Aug 20, 2026', '3.00', 'row'],
                    ['Applied to the Aug 5, 2026 – Aug 20, 2026 pool', '3.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                    ['Deferred work applied to free capacity (1 entry)', '1.00', 'row'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Total catch-up billed on this invoice', '0.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Unused hours rolling into the next period', '0.00', 'row'],
                    ['Unused hours expiring at the start of the next period', '0.00', 'row'],
                    ['Hours still owed, carried into the next period', '0.00', 'row'],
                    ['Deferred work waiting for free capacity (1 entry)', '0.50', 'row'],
                ]],
                ['Closing position: the next period', [
                    ['Retainer hours for the next period', '5.00', 'row'],
                    ['Unused hours rolled in', '0.00', 'row'],
                    ['Hours owed carried in', '0.00', 'row'],
                    ['Net hours available at the start of the next period', '5.00', 'total'],
                ]],
            ],
        ];

        yield 'ordinary: a period across a year end' => [
            [
                'workStart' => '2026-12-01', 'workEnd' => '2027-02-28', 'retainerStart' => '2027-03-01', 'retainerEnd' => '2027-03-31',
                'openingRetainerHours' => 6.0, 'nextRetainerHours' => 6.0,
            ],
            self::ORDINARY_INTRO,
            [
                ['Opening pool: December 2026 – February 2027', [
                    ['Retainer hours for December 2026 – February 2027', '6.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '0.00', 'row'],
                    ['Net hours available at the start of December 2026 – February 2027', '6.00', 'total'],
                ]],
                ['Work reconciled on this invoice', [
                    ['Hours worked in December 2026 – February 2027', '0.00', 'row'],
                    ['Applied to the December 2026 – February 2027 pool', '0.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Total catch-up billed on this invoice', '0.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Unused hours rolling into March 2027', '0.00', 'row'],
                    ['Unused hours expiring at the start of March 2027', '0.00', 'row'],
                    ['Hours still owed, carried into March 2027', '0.00', 'row'],
                ]],
                ['Closing position: March 2027', [
                    ['Retainer hours for March 2027', '6.00', 'row'],
                    ['Unused hours rolled in', '0.00', 'row'],
                    ['Hours owed carried in', '0.00', 'row'],
                    ['Net hours available at the start of March 2027', '6.00', 'total'],
                ]],
            ],
        ];

        yield 'ordinary: minimum availability row shows for a threshold even when nothing was restored' => [
            ['openingRetainerHours' => 10.0, 'minimumAvailabilityThresholdHours' => 8.0, 'nextRetainerHours' => 10.0],
            self::ORDINARY_INTRO,
            [
                ['Opening pool: August 2026', [
                    ['Retainer hours for August 2026', '10.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '0.00', 'row'],
                    ['Net hours available at the start of August 2026', '10.00', 'total'],
                ]],
                ['Work reconciled on this invoice', [
                    ['Hours worked in August 2026', '0.00', 'row'],
                    ['Applied to the August 2026 pool', '0.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Minimum availability: restores the agreement\'s 8.00-hour minimum for September 2026', '0.00', 'row'],
                    ['Total catch-up billed on this invoice', '0.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Unused hours rolling into September 2026', '0.00', 'row'],
                    ['Unused hours expiring at the start of September 2026', '0.00', 'row'],
                    ['Hours still owed, carried into September 2026', '0.00', 'row'],
                ]],
                ['Closing position: September 2026', [
                    ['Retainer hours for September 2026', '10.00', 'row'],
                    ['Unused hours rolled in', '0.00', 'row'],
                    ['Hours owed carried in', '0.00', 'row'],
                    ['Net hours available at the start of September 2026', '10.00', 'total'],
                ]],
            ],
        ];

        yield 'ordinary: restored hours show the minimum row even with no stored threshold' => [
            ['openingRetainerHours' => 10.0, 'minimumAvailabilityHours' => 3.0, 'catchUpBilledHours' => 3.0, 'nextRetainerHours' => 10.0],
            self::ORDINARY_INTRO,
            [
                ['Opening pool: August 2026', [
                    ['Retainer hours for August 2026', '10.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '0.00', 'row'],
                    ['Net hours available at the start of August 2026', '10.00', 'total'],
                ]],
                ['Work reconciled on this invoice', [
                    ['Hours worked in August 2026', '0.00', 'row'],
                    ['Applied to the August 2026 pool', '0.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Minimum availability: restores the agreement\'s 0.00-hour minimum for September 2026', '3.00', 'row'],
                    ['Total catch-up billed on this invoice', '3.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Unused hours rolling into September 2026', '0.00', 'row'],
                    ['Unused hours expiring at the start of September 2026', '0.00', 'row'],
                    ['Hours still owed, carried into September 2026', '0.00', 'row'],
                ]],
                ['Closing position: September 2026', [
                    ['Retainer hours for September 2026', '10.00', 'row'],
                    ['Unused hours rolled in', '0.00', 'row'],
                    ['Hours owed carried in', '0.00', 'row'],
                    ['Net hours available at the start of September 2026', '10.00', 'total'],
                ]],
            ],
        ];

        yield 'ordinary: hours that round to nothing print no optional row' => [
            [
                'openingRetainerHours' => 4.0, 'openingExpiredHours' => 0.004, 'expiredWithinPeriodHours' => 0.004,
                'ordinaryAppliedToNextRetainer' => 0.004, 'subcontractorHours' => 0.004, 'deferredAppliedHours' => 0.004,
                'recarriedSettledHours' => 0.004, 'deferredBilledOnTerminationHours' => 0.004, 'interimBilledHours' => 0.004,
                'minimumAvailabilityThresholdHours' => 0.004, 'deferredBacklogHours' => 0.004, 'recarriedRemainingHours' => 0.004,
                'nextRetainerHours' => 4.0,
            ],
            self::ORDINARY_INTRO,
            [
                ['Opening pool: August 2026', [
                    ['Retainer hours for August 2026', '4.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '0.00', 'row'],
                    ['Net hours available at the start of August 2026', '4.00', 'total'],
                ]],
                ['Work reconciled on this invoice', [
                    ['Hours worked in August 2026', '0.00', 'row'],
                    ['Applied to the August 2026 pool', '0.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Total catch-up billed on this invoice', '0.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Unused hours rolling into September 2026', '0.00', 'row'],
                    ['Unused hours expiring at the start of September 2026', '0.00', 'row'],
                    ['Hours still owed, carried into September 2026', '0.00', 'row'],
                ]],
                ['Closing position: September 2026', [
                    ['Retainer hours for September 2026', '4.00', 'row'],
                    ['Unused hours rolled in', '0.00', 'row'],
                    ['Hours owed carried in', '0.00', 'row'],
                    ['Net hours available at the start of September 2026', '4.00', 'total'],
                ]],
            ],
        ];

        yield 'ordinary: a deficit opens and carries, as negative hours' => [
            [
                'openingRetainerHours' => 10.0, 'openingDeficitHours' => 4.0, 'ordinaryHours' => 12.0,
                'ordinaryAppliedToWorkPool' => 6.0, 'ordinaryBilledAtRate' => 6.0, 'catchUpBilledHours' => 6.0,
                'deficitCarriedForwardHours' => 2.0, 'nextRetainerHours' => 10.0,
            ],
            self::ORDINARY_INTRO,
            [
                ['Opening pool: August 2026', [
                    ['Retainer hours for August 2026', '10.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '-4.00', 'row'],
                    ['Net hours available at the start of August 2026', '6.00', 'total'],
                ]],
                ['Work reconciled on this invoice', [
                    ['Hours worked in August 2026', '12.00', 'row'],
                    ['Applied to the August 2026 pool', '6.00', 'detail'],
                    ['Billed at the hourly rate', '6.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '6.00', 'row'],
                    ['Total catch-up billed on this invoice', '6.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Unused hours rolling into September 2026', '0.00', 'row'],
                    ['Unused hours expiring at the start of September 2026', '0.00', 'row'],
                    ['Hours still owed, carried into September 2026', '2.00', 'row'],
                ]],
                ['Closing position: September 2026', [
                    ['Retainer hours for September 2026', '10.00', 'row'],
                    ['Unused hours rolled in', '0.00', 'row'],
                    ['Hours owed carried in', '-2.00', 'row'],
                    ['Net hours available at the start of September 2026', '8.00', 'total'],
                ]],
            ],
        ];

        yield 'correction: a plain one, nothing recorded after the fact' => [
            [
                'workStart' => '2026-08-10', 'workEnd' => '2026-08-14', 'retainerStart' => '2026-08-01', 'retainerEnd' => '2026-08-31',
                'retainerSoldBy' => 'INV-SYN-0001',
                'openingRetainerHours' => 10.0, 'openingRolloverHours' => 2.0, 'openingDeficitHours' => 0.5,
                'ordinaryHours' => 4.0, 'ordinaryAppliedToWorkPool' => 4.0,
            ],
            self::CORRECTION_INTRO,
            [
                ['Pool position for August 2026 (sold on invoice INV-SYN-0001)', [
                    ['Retainer hours for August 2026', '10.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '2.00', 'row'],
                    ['Hours owed from earlier periods', '-0.50', 'row'],
                    ['Available before this correction\'s work', '11.50', 'total'],
                    ['This invoice corrects work within August 2026. It does not sell the August 2026 retainer and charges nothing for it; that was sold on invoice INV-SYN-0001.', '', 'note'],
                ]],
                ['Work reconciled on this correction: Aug 10, 2026 – Aug 14, 2026', [
                    ['Hours worked in Aug 10, 2026 – Aug 14, 2026', '4.00', 'row'],
                    ['Applied to the August 2026 pool', '4.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Total catch-up billed on this invoice', '0.00', 'total'],
                ]],
            ],
        ];

        yield 'correction: every optional row, with the pool before and after' => [
            [
                'workStart' => '2026-08-10', 'workEnd' => '2026-08-14', 'retainerStart' => '2026-08-01', 'retainerEnd' => '2026-08-31',
                'retainerSoldBy' => 'INV-SYN-0001',
                'openingRetainerHours' => 10.0, 'openingRolloverHours' => 2.0, 'openingDeficitHours' => 0.5, 'openingExpiredHours' => 1.0,
                'availableBeforeHours' => 9.0, 'poolRemainingHours' => 3.25,
                'ordinaryHours' => 7.0, 'ordinaryAppliedToWorkPool' => 4.0, 'ordinaryAppliedToNextRetainer' => 1.0, 'ordinaryBilledAtRate' => 2.0,
                'subcontractorHours' => 0.5, 'deferredAppliedHours' => 1.5, 'deferredAppliedEntries' => 3,
                'recarriedSettledHours' => 0.25, 'deferredBilledOnTerminationHours' => 2.0, 'catchUpBilledHours' => 3.0,
                'minimumAvailabilityHours' => 1.0, 'minimumAvailabilityThresholdHours' => 6.0,
                // Recorded on the statement but never printed on a correction.
                'interimBilledHours' => 0.75, 'expiredWithinPeriodHours' => 1.0, 'rolledForwardHours' => 5.0,
                'expiringHours' => 1.0, 'deficitCarriedForwardHours' => 0.5, 'nextRetainerHours' => 10.0,
                'deferredBacklogHours' => 2.0, 'deferredBacklogEntries' => 2, 'recarriedRemainingHours' => 0.25,
            ],
            self::CORRECTION_INTRO,
            [
                ['Pool position for August 2026 (sold on invoice INV-SYN-0001)', [
                    ['Retainer hours for August 2026', '10.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '2.00', 'row'],
                    ['Hours owed from earlier periods', '-0.50', 'row'],
                    ['Already drawn on the August 2026 pool before this correction\'s range', '-2.50', 'row'],
                    ['Available before this correction\'s work', '9.00', 'total'],
                    ['Unused hours that expired at the start of August 2026', '1.00', 'note'],
                    ['This invoice corrects work within August 2026. It does not sell the August 2026 retainer and charges nothing for it; that was sold on invoice INV-SYN-0001.', '', 'note'],
                ]],
                ['Work reconciled on this correction: Aug 10, 2026 – Aug 14, 2026', [
                    ['Hours worked in Aug 10, 2026 – Aug 14, 2026', '7.00', 'row'],
                    ['Applied to the August 2026 pool', '4.00', 'detail'],
                    ['Applied to the August 2026 pool on a second line', '1.00', 'detail'],
                    ['Billed at the hourly rate', '2.00', 'detail'],
                    ['Subcontractor hours billed separately at their own rate (not drawn on the pool)', '0.50', 'row'],
                    ['Deferred work applied to free capacity (3 entries)', '1.50', 'row'],
                    ['Earlier deferred work settled from free capacity', '0.25', 'row'],
                    ['Deferred work billed at the hourly rate on termination', '2.00', 'row'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '2.00', 'row'],
                    ['Minimum availability: restores the agreement\'s 6.00-hour minimum for August 2026', '1.00', 'row'],
                    ['Total catch-up billed on this invoice', '3.00', 'total'],
                ]],
                ['Pool position after this correction', [
                    ['Remaining in the August 2026 pool', '3.25', 'total'],
                ]],
                ['Carried forward', [
                    ['Deferred work waiting for free capacity (2 entries)', '2.00', 'row'],
                    ['Earlier deferred work still to settle', '0.25', 'row'],
                ]],
            ],
        ];

        yield 'correction: nothing drawn earlier in the month, an empty pool afterwards, singular backlog' => [
            [
                'workStart' => '2026-08-10', 'workEnd' => '2026-08-14', 'retainerStart' => '2026-08-01', 'retainerEnd' => '2026-08-31',
                'retainerSoldBy' => 'INV-SYN-0001',
                'openingRetainerHours' => 6.0, 'availableBeforeHours' => 6.0, 'poolRemainingHours' => 0.0,
                'ordinaryHours' => 6.0, 'ordinaryAppliedToWorkPool' => 6.0,
                'deferredBacklogHours' => 1.0, 'deferredBacklogEntries' => 1,
            ],
            self::CORRECTION_INTRO,
            [
                ['Pool position for August 2026 (sold on invoice INV-SYN-0001)', [
                    ['Retainer hours for August 2026', '6.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '0.00', 'row'],
                    ['Available before this correction\'s work', '6.00', 'total'],
                    ['This invoice corrects work within August 2026. It does not sell the August 2026 retainer and charges nothing for it; that was sold on invoice INV-SYN-0001.', '', 'note'],
                ]],
                ['Work reconciled on this correction: Aug 10, 2026 – Aug 14, 2026', [
                    ['Hours worked in Aug 10, 2026 – Aug 14, 2026', '6.00', 'row'],
                    ['Applied to the August 2026 pool', '6.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Total catch-up billed on this invoice', '0.00', 'total'],
                ]],
                ['Pool position after this correction', [
                    ['Remaining in the August 2026 pool', '0.00', 'total'],
                ]],
                ['Carried forward', [
                    ['Deferred work waiting for free capacity (1 entry)', '1.00', 'row'],
                ]],
            ],
        ];

        yield 'correction: minimum availability row with a restored amount and no threshold' => [
            [
                'workStart' => '2026-08-10', 'workEnd' => '2026-08-14', 'retainerStart' => '2026-08-01', 'retainerEnd' => '2026-08-31',
                'retainerSoldBy' => 'INV-SYN-0002', 'openingRetainerHours' => 3.0,
                'minimumAvailabilityHours' => 2.0, 'catchUpBilledHours' => 2.0,
            ],
            self::CORRECTION_INTRO,
            [
                ['Pool position for August 2026 (sold on invoice INV-SYN-0002)', [
                    ['Retainer hours for August 2026', '3.00', 'row'],
                    ['Unused hours rolled in from earlier periods', '0.00', 'row'],
                    ['Hours owed from earlier periods', '0.00', 'row'],
                    ['Available before this correction\'s work', '3.00', 'total'],
                    ['This invoice corrects work within August 2026. It does not sell the August 2026 retainer and charges nothing for it; that was sold on invoice INV-SYN-0002.', '', 'note'],
                ]],
                ['Work reconciled on this correction: Aug 10, 2026 – Aug 14, 2026', [
                    ['Hours worked in Aug 10, 2026 – Aug 14, 2026', '0.00', 'row'],
                    ['Applied to the August 2026 pool', '0.00', 'detail'],
                    ['Billed at the hourly rate', '0.00', 'detail'],
                ]],
                ['Catch-up billed at the hourly rate', [
                    ['Work beyond the available pool', '0.00', 'row'],
                    ['Minimum availability: restores the agreement\'s 0.00-hour minimum for August 2026', '2.00', 'row'],
                    ['Total catch-up billed on this invoice', '2.00', 'total'],
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<array{string, list<array{string, string, string}>}>  $expected
     */
    #[DataProvider('scenarios')]
    public function test_the_statement_prints_exactly_these_sections_and_rows(array $overrides, string $intro, array $expected): void
    {
        $invoice = $this->goldenInvoice(['hours_statement' => $this->goldenStatement($overrides)->toArray()]);

        $printed = [];
        foreach ([InvoiceLineDetail::OPERATOR, InvoiceLineDetail::CLIENT] as $audience) {
            $statement = $this->documentFacts()->extract($invoice, $audience)['hours_statement'];
            $this->assertNotNull($statement, $audience);
            $this->assertSame($intro, $statement['intro'], $audience);
            $printed[$audience] = array_map(
                static fn (array $section): array => [$section['title'], array_map(
                    static fn (array $row): array => [$row['label'], $row['value'], $row['kind']],
                    $section['rows'],
                )],
                $statement['sections'],
            );
            $this->assertSame($expected, $printed[$audience], $audience);
        }

        $this->assertSame($printed[InvoiceLineDetail::OPERATOR], $printed[InvoiceLineDetail::CLIENT], 'The statement is the same for both audiences.');
    }

    #[DataProvider('audiencesProvider')]
    public function test_an_invoice_with_no_stored_statement_prints_none(string $audience): void
    {
        $invoice = $this->goldenInvoice();

        $this->assertNull($this->documentFacts()->extract($invoice, $audience)['hours_statement']);
    }

    #[DataProvider('audiencesProvider')]
    public function test_a_statement_of_an_unknown_version_prints_nothing(string $audience): void
    {
        $stored = [...$this->goldenStatement(['openingRetainerHours' => 10.0])->toArray(), 'version' => 99];
        $invoice = $this->goldenInvoice(['hours_statement' => $stored]);

        $this->assertNull($this->documentFacts()->extract($invoice, $audience)['hours_statement']);
    }

    /** @return iterable<string, array{string}> */
    public static function audiencesProvider(): iterable
    {
        yield 'operator' => [InvoiceLineDetail::OPERATOR];
        yield 'client' => [InvoiceLineDetail::CLIENT];
    }
}
