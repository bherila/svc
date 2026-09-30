<?php

namespace Tests\Unit\Casts;

use App\Casts\DateOnly;
use App\Models\ClientTimeEntry;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bare-date cast, without a database (#354).
 *
 * The diff-scoped mutation lane leans on tests that cover a line directly, so
 * the conversion both ways is pinned here as well as through the feature
 * tests that store and query real rows.
 */
final class DateOnlyTest extends TestCase
{
    #[DataProvider('writes')]
    public function test_it_writes_only_the_date(mixed $given, ?string $stored): void
    {
        $model = new ClientTimeEntry;

        $this->assertSame($stored, (new DateOnly)->set($model, 'worked_on', $given, []));
        $this->assertSame($stored, DateOnly::toStored($given));
    }

    public static function writes(): iterable
    {
        yield 'a date string' => ['2026-01-31', '2026-01-31'];
        yield 'a datetime string' => ['2026-01-31 17:45:00', '2026-01-31'];
        yield 'a date object with a time' => [new DateTimeImmutable('2026-01-31 23:59:59'), '2026-01-31'];
        yield 'a timestamp, as immutable_date accepted' => [CarbonImmutable::parse('2025-01-31')->getTimestamp(), '2025-01-31'];
        yield 'a timestamp given as a numeric string' => [(string) CarbonImmutable::parse('2025-01-31')->getTimestamp(), '2025-01-31'];
        yield 'null' => [null, null];
        yield 'an empty string' => ['', null];
    }

    #[DataProvider('notDates')]
    public function test_it_refuses_a_value_that_is_not_a_date(mixed $given): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateOnly::toStored($given);
    }

    public static function notDates(): iterable
    {
        yield 'a boolean' => [true];
        yield 'an array' => [['2026-01-31']];
        yield 'a float' => [1.5];
    }

    #[DataProvider('reads')]
    public function test_it_reads_the_date_at_midnight(mixed $stored, ?string $read): void
    {
        $value = (new DateOnly)->get(new ClientTimeEntry, 'worked_on', $stored, []);

        if ($read === null) {
            $this->assertNull($value);

            return;
        }

        $this->assertInstanceOf(CarbonImmutable::class, $value);
        $this->assertSame($read, $value->toDateTimeString());
    }

    public static function reads(): iterable
    {
        yield 'a bare date' => ['2026-01-31', '2026-01-31 00:00:00'];
        // A row SQLite stored before the cast, which the migration strips.
        yield 'a legacy value with a time' => ['2026-01-31 00:00:00', '2026-01-31 00:00:00'];
        yield 'a legacy value with a non-midnight time' => ['2026-01-31 13:14:15', '2026-01-31 00:00:00'];
        yield 'null' => [null, null];
        yield 'an empty string' => ['', null];
    }

    public function test_the_model_casts_worked_on_with_it(): void
    {
        $entry = new ClientTimeEntry;
        $entry->worked_on = '2026-01-31 08:00:00';

        $this->assertSame('2026-01-31', $entry->getAttributes()['worked_on']);
        $this->assertSame('2026-01-31', $entry->worked_on->toDateString());
    }
}
