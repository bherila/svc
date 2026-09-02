<?php

namespace Tests\Feature\Billing;

use App\Http\Requests\Engagement\StoreAgreementRequest;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\ExternalImport\ExternalImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;
use Throwable;
use UnexpectedValueException;

/**
 * An agreement states when it starts, and there is one way to say otherwise: none.
 *
 * `client_agreements.starts_on` had at least seven readings and several were
 * incompatible. `AgreementBillingRateResolver::resolve()` and
 * `ClientCompany::activeAgreement()` treated a null as **in force**;
 * `TimeSheetController::capacityByMonth` and every date-based selector treated
 * it as **excluded**; `BillingCycleResolver::cyclesForAgreement()` treated it as
 * **fatal**; and `cycleContaining()` answered two different ways depending on
 * cadence. So an undated agreement could stamp its hourly rate onto approved
 * time while contributing no capacity to the sheet that time was logged on, and
 * while being invisible to the selector that would have billed it (#147).
 *
 * ## Why this is one test class and not seven assertions
 *
 * The issue's acceptance criteria asks for tests showing every surface agrees,
 * "not one test per surface asserting current behaviour" - and that distinction
 * is the reason the contract is a `NOT NULL` column rather than a documented
 * convention. Seven readers cannot disagree about a state that cannot exist, so
 * what has to be pinned is not seven behaviours but the four places a null could
 * still be written: the column itself, the HTTP edge, the service edge, and the
 * importer. Each is asserted below, and nothing else needs asserting.
 *
 * The migration's own refusal is asserted too, against a real pre-migration
 * schema in a throwaway database - because the one database this constraint has
 * to cross is a restored legacy one, and a migration that silently invented
 * dates there would rewrite what those agreements billed.
 */
final class AgreementStartDateContractTest extends TestCase
{
    use RefreshDatabase, UsesAProbeDatabase;

    private const CONNECTION = 'agreement_start_probe';

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Client',
            'slug' => 'client',
        ]);
    }

    /**
     * The column, on the engine that ships.
     *
     * Everything else here is a guard in front of this one. Asserted first and
     * separately because a schema that quietly accepted the null would leave
     * every other assertion in this file proving only that the application is
     * polite about a state it can still hold.
     */
    public function test_the_column_refuses_an_agreement_with_no_start_date(): void
    {
        $this->expectException(QueryException::class);

        DB::table('client_agreements')->insert([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'public_id' => (string) str()->uuid(),
            'title' => 'Undated',
            'status' => 'active',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'starts_on' => null,
        ]);
    }

    /**
     * The HTTP edge names it required rather than nullable.
     *
     * Asserted through the rule set rather than a request, because the failure
     * this guards against is the rule reverting to `nullable` - at which point a
     * request would simply be rejected one layer later by the column, and a test
     * that only watched the response could not tell the two apart.
     */
    public function test_the_request_requires_a_start_date(): void
    {
        $rules = (new StoreAgreementRequest)->rules();

        $this->assertContains('required', $rules['starts_on']);
        $this->assertNotContains('nullable', $rules['starts_on']);
    }

    /**
     * The service edge does not substitute one either.
     *
     * `AgreementWorkflow::create()` used to read `$attributes['starts_on'] ??
     * null`, which turned a caller that forgot the field into an undated
     * agreement rather than an error. Bypassing the form request is exactly how
     * that happened - the agent API and any console caller reach this directly.
     */
    public function test_the_workflow_refuses_to_create_an_agreement_without_one(): void
    {
        $this->expectException(Throwable::class);

        app(AgreementWorkflow::class)->create(
            $this->workspace,
            $this->company,
            null,
            null,
            ['title' => 'No date', 'currency' => 'USD'],
        );
    }

    /**
     * The importer refuses the source row rather than dating it.
     *
     * The undated population was always an imported one - `ProposalWorkflow::
     * accept()` sets the date on every natively created agreement - so this is
     * the edge that mattered. It refuses because every candidate default writes
     * a different billing history: today's date leaves past work unpriced, the
     * earliest invoice's date invents a term nobody agreed, and the epoch grants
     * capacity for years that never existed.
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('sourceRows')]
    public function test_the_importer_refuses_a_source_agreement_with_no_start_date(array $row, string $because): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The source agreement states no start date');

        ExternalImportService::importedAgreementStart($row);
        $this->fail($because);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function sourceRows(): array
    {
        return [
            'absent' => [[], 'A row with no active_date key at all must refuse.'],
            'null' => [['active_date' => null], 'An explicit null must refuse.'],
            'empty' => [['active_date' => ''], 'An empty string is not a date.'],
        ];
    }

    /** A source row that states one is carried through unchanged. */
    public function test_the_importer_carries_a_stated_start_date(): void
    {
        $this->assertSame(
            '2024-03-01',
            ExternalImportService::importedAgreementStart(['active_date' => '2024-03-01 09:30:00']),
        );
    }

    /**
     * The migration refuses a database that still holds an undated agreement.
     *
     * This is the case the constraint exists to meet: a restore from before it.
     * Both databases in play were sized first and came back clean - 0 undated of
     * 9 in production, 0 of 9 in the source - so the refusal has never fired for
     * real, which is exactly why it needs a test. A migration that backfilled
     * instead would rewrite which cycles those agreements had, what capacity
     * each period granted, and which of them priced a given day's work.
     *
     * Run against a real pre-migration schema in a throwaway database of its
     * own, on whichever engine the suite is pointed at, because a structural
     * assertion about `NOT NULL` proves nothing about what the engine does with
     * a row already sitting in the table.
     */
    public function test_the_migration_refuses_rather_than_dating_an_existing_agreement(): void
    {
        $this->bootProbeDatabase(self::CONNECTION);

        // Everything up to, but not including, the migration under test.
        Artisan::call('migrate', [
            '--database' => self::CONNECTION,
            '--force' => true,
            '--step' => true,
        ]);
        Artisan::call('migrate:rollback', [
            '--database' => self::CONNECTION,
            '--step' => 1,
            '--force' => true,
        ]);

        $this->assertTrue(
            $this->startDateIsNullableOnTheProbe(),
            'The rollback did not restore the nullable column, so this test would prove nothing.',
        );

        $workspace = DB::connection(self::CONNECTION)->table('workspaces')->insertGetId([
            'name' => 'Probe', 'slug' => 'probe', 'public_id' => (string) str()->uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $company = DB::connection(self::CONNECTION)->table('client_companies')->insertGetId([
            'workspace_id' => $workspace, 'name' => 'Probe', 'slug' => 'probe',
            'public_id' => (string) str()->uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection(self::CONNECTION)->table('client_agreements')->insert([
            'workspace_id' => $workspace, 'client_company_id' => $company,
            'public_id' => (string) str()->uuid(), 'title' => 'Undated', 'status' => 'active',
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'starts_on' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
            $this->fail('The migration ran to completion over an undated agreement.');
        } catch (RuntimeException $refusal) {
            // The whole message. It has to state the count - an operator needs
            // to know whether this is one row or four hundred before deciding
            // how to date them - and it has to carry the query that finds them,
            // since the audit that used to characterise this population was
            // retired with the ambiguity it measured.
            $this->assertSame(
                '1 client agreement(s) have no start date, and there is no date this migration could supply '
                .'that would not rewrite what they billed. Find them with `SELECT id, status, billing_cadence '
                .'FROM client_agreements WHERE starts_on IS NULL`, set a start date on each from the agreement '
                .'itself, then migrate again.',
                $refusal->getMessage(),
            );
        }

        // And it left the row alone rather than dating it on the way out.
        $this->assertNull(
            DB::connection(self::CONNECTION)->table('client_agreements')->value('starts_on'),
            'The migration wrote a start date it had refused to choose.',
        );
    }

    private function startDateIsNullableOnTheProbe(): bool
    {
        foreach (Schema::connection(self::CONNECTION)->getColumns('client_agreements') as $column) {
            if ($column['name'] === 'starts_on') {
                return (bool) $column['nullable'];
            }
        }

        $this->fail('The probe database has no client_agreements.starts_on column.');
    }
}
