<?php

namespace Tests\Feature\Billing;

use App\Casts\DateOnly;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Work on the last day of a period belongs to that period on every driver (#354).
 *
 * `immutable_date` wrote `2026-01-31 00:00:00`. MariaDB's `DATE` column drops
 * the time; SQLite keeps the text, so `<= '2026-01-31'` excluded the entry and
 * a month-end billing bug could pass the local suite. The property has two
 * halves, and each has a test here: the column stores a bare date, and every
 * range bound on it in `app/` is a date string rather than a Carbon, which the
 * query builder formats with a time.
 */
final class WorkedOnDateBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Month end', 'slug' => 'month-end']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Month End Client', 'slug' => 'month-end-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Month End Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_worked_on_is_stored_as_a_bare_date_whatever_it_was_given(): void
    {
        $fromString = $this->entry('2026-01-31');
        $fromCarbon = $this->entry(Carbon::parse('2026-02-28 00:00:00'));
        $fromDateTimeString = $this->entry('2026-03-31 17:45:00');

        $this->assertSame('2026-01-31', $this->stored($fromString));
        $this->assertSame('2026-02-28', $this->stored($fromCarbon));
        $this->assertSame('2026-03-31', $this->stored($fromDateTimeString));

        $read = $fromString->fresh()?->worked_on;
        $this->assertInstanceOf(CarbonImmutable::class, $read);
        $this->assertSame('2026-01-31 00:00:00', $read->toDateTimeString());
    }

    public function test_the_first_and_last_day_of_a_month_are_inside_its_date_string_range(): void
    {
        $this->entry('2025-12-31');
        $first = $this->entry('2026-01-01');
        $last = $this->entry('2026-01-31');
        $this->entry('2026-02-01');

        $between = ClientTimeEntry::query()
            ->whereBetween('worked_on', ['2026-01-01', '2026-01-31'])
            ->pluck('id')->sort()->values()->all();
        $bounded = ClientTimeEntry::query()
            ->where('worked_on', '>=', '2026-01-01')
            ->where('worked_on', '<=', '2026-01-31')
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([$first->id, $last->id], $between);
        $this->assertSame([$first->id, $last->id], $bounded);
    }

    /** Serialised exactly as `immutable_date` was, so no payload changes. */
    public function test_it_serialises_as_immutable_date_did(): void
    {
        $entry = $this->entry('2026-01-31');

        $this->assertSame(
            CarbonImmutable::parse('2026-01-31')->startOfDay()->toJSON(),
            $entry->fresh()?->toArray()['worked_on'],
        );
    }

    /** Input is converted as `immutable_date` converted it, then kept to the date. */
    public function test_the_stored_form_accepts_what_immutable_date_accepted(): void
    {
        $model = new ClientTimeEntry;

        $this->assertSame('2025-01-31', DateOnly::toStored($model, CarbonImmutable::parse('2025-01-31')->getTimestamp()));
        $this->assertSame('2025-01-31', DateOnly::toStored($model, '2025-01-31'));
        $this->assertSame('2025-01-31', DateOnly::toStored($model, '2025-01-31 23:59:59'));
        $this->assertSame('2025-01-31', DateOnly::toStored($model, new DateTimeImmutable('2025-01-31 08:00:00')));
        $this->assertNull(DateOnly::toStored($model, null));
    }

    /** A builder update skips casts, so the mutation path writes the stored form itself. */
    public function test_the_agent_update_path_stores_a_bare_date(): void
    {
        $entry = $this->entry('2026-01-10');
        $entry->forceFill(['status' => 'draft'])->save();
        $this->workspace->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);

        app(TimeEntryMutationService::class)->update($this->workspace, $entry, $this->user, [
            'expected_version' => AgentApiVersion::for($entry),
            'worked_on' => Carbon::parse('2026-01-31 00:00:00'),
        ]);

        $this->assertSame('2026-01-31', $this->stored($entry));
    }

    /** Rows written on SQLite before the cast lose their meaningless midnight. */
    public function test_the_migration_strips_the_time_from_rows_written_before_the_cast(): void
    {
        $entry = $this->entry('2026-01-31');
        DB::table('client_time_entries')->where('id', $entry->id)->update(['worked_on' => '2026-01-31 00:00:00']);
        // Only SQLite keeps the time; MariaDB's DATE drops it on write, so
        // there the migration has nothing to do and the row is already bare.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame('2026-01-31 00:00:00', $this->stored($entry));
        }

        (require database_path('migrations/2026_09_29_010000_store_time_entry_dates_without_a_time.php'))->up();

        $this->assertSame('2026-01-31', $this->stored($entry));
    }

    /**
     * Every range bound on `worked_on` in `app/` is a date string.
     *
     * A Carbon bound is formatted as `Y-m-d H:i:s`, and against a bare stored
     * date SQLite answers `'2026-01-01' >= '2026-01-01 00:00:00'` false - the
     * first day of the period drops out instead of the last. So each bound, on
     * its own, must end in `toDateString()`, be a date literal, or be a variable
     * named below that is already known to hold a date string. `whereDate`
     * formats its own bound and is not a range bound here.
     *
     * Calls are read whole, across lines, with the column aliased or not.
     */
    public function test_every_worked_on_range_bound_in_the_application_is_a_date_string(): void
    {
        $reviewedStringBounds = [
            // Built by `toDateString()` in `currentCycle()`.
            'app/Http/Controllers/ClientDirectoryController.php' => ['$window[\'start\']', '$window[\'end\']'],
            // Split from the replay's invoice key, whose period is built from
            // `toDateString()` or '?' in `key()`; '?' and '' are skipped.
            'app/Console/Commands/Billing/ReplayInvoicesCommand.php' => ['$periodStart', '$periodEnd'],
            // `$upTo?->toDateString()`, taken before the query.
            'app/Services/Billing/DeferredBillingAllocator.php' => ['$upToDate'],
        ];

        $sites = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'app/'.ltrim(substr($file->getPathname(), strlen(app_path())), '/');
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/->(\\w+)\\(\\s*'(?:\\w+\\.)?worked_on'\\s*,/", $source, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as $index => [$call, $offset]) {
                $method = $calls[1][$index][0];
                if (! in_array($method, ['where', 'orWhere', 'whereNot', 'orWhereNot', 'whereBetween', 'orWhereBetween', 'whereNotBetween', 'whereIn', 'whereNotIn'], true)) {
                    continue;
                }
                $site = $relative.':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
                $sites[] = $site;
                $arguments = self::topLevelArguments(substr($source, $offset + strlen($call)));
                $bounds = str_starts_with(trim((string) end($arguments)), '[')
                    ? self::topLevelArguments(substr(trim((string) end($arguments)), 1, -1).')')
                    : [end($arguments)];
                foreach ($bounds as $bound) {
                    $bound = trim((string) $bound);
                    $safe = str_ends_with($bound, 'toDateString()')
                        || preg_match("/^'\\d{4}-\\d{2}-\\d{2}'$/", $bound) === 1
                        || in_array($bound, $reviewedStringBounds[$relative] ?? [], true);
                    $this->assertTrue($safe, "{$site} bounds worked_on with something not known to be a date string: {$bound}");
                }
            }
        }

        // The scan found the sites it is meant to guard; an empty scan passes vacuously.
        $this->assertGreaterThanOrEqual(15, count($sites), implode("\n", $sites));
    }

    /**
     * The comma-separated arguments of a call, read from just inside its
     * opening parenthesis to the parenthesis that closes it.
     *
     * @return list<string>
     */
    private static function topLevelArguments(string $rest): array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $quote = null;
        foreach (str_split($rest) as $character) {
            if ($quote !== null) {
                $current .= $character;
                $quote = $character === $quote ? null : $quote;

                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif (in_array($character, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($character, [')', ']'], true)) {
                if ($depth === 0) {
                    $arguments[] = $current;

                    return $arguments;
                }
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = '';

                continue;
            }
            $current .= $character;
        }

        return $arguments;
    }

    private function entry(string|Carbon $workedOn): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => 60,
            'description' => 'Month-end work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    private function stored(ClientTimeEntry $entry): string
    {
        return (string) DB::table('client_time_entries')->where('id', $entry->id)->value('worked_on');
    }
}
