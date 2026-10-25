<?php

namespace Tests\Unit\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Support\Billing\EmptyCadenceDraft;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which generated drafts bill nothing, without a database. */
final class EmptyCadenceDraftTest extends TestCase
{
    /** @return iterable<string, array{int, list<array{int, int, int}>, bool}> */
    public static function drafts(): iterable
    {
        // [draft total, [[line total, billable entries, milestones], ...], bills nothing]
        yield 'a $0 retainer line alone' => [0, [[0, 0, 0]], true];
        yield 'no lines at all' => [0, [], true];
        yield 'several $0 lines' => [0, [[0, 0, 0], [0, 0, 0]], true];
        yield 'a priced retainer' => [60000, [[60000, 0, 0]], false];
        yield 'a $0 draft total over a priced line and its credit' => [0, [[0, 0, 0], [5000, 0, 0]], false];
        yield 'billable time at a $0 rate' => [0, [[0, 0, 0], [0, 1, 0]], false];
        yield 'a $0 milestone' => [0, [[0, 0, 1]], false];
        yield 'a total the lines do not explain' => [100, [[0, 0, 0]], false];
    }

    /** @param list<array{int, int, int}> $lines */
    #[DataProvider('drafts')]
    public function test_a_draft_bills_nothing_only_when_nothing_on_it_is_billed(int $total, array $lines, bool $expected): void
    {
        $draft = new ClientInvoice;
        $draft->forceFill(['total_amount' => $total]);
        $draft->setRelation('lines', new Collection(array_map(static function (array $line): ClientInvoiceLine {
            [$amount, $entries, $milestones] = $line;
            $model = new ClientInvoiceLine;
            $model->forceFill(['total_amount' => $amount]);
            $model->setRelation('timeEntries', new Collection(array_fill(0, $entries, new ClientTimeEntry)));
            $model->setRelation('tasks', new Collection(array_fill(0, $milestones, new ClientTask)));

            return $model;
        }, $lines)));

        $this->assertSame($expected, EmptyCadenceDraft::billsNothing($draft));
    }
}
