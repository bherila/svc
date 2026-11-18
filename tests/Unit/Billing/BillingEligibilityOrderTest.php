<?php

namespace Tests\Unit\Billing;

use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Providers\AppServiceProvider;
use App\Services\Billing\BillingEligibilityOrder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/** Real physical commits for the ordering component and its application wiring. */
#[CoversClass(BillingEligibilityOrder::class)]
#[CoversClass(ClientCompany::class)]
#[CoversClass(AppServiceProvider::class)]
final class BillingEligibilityOrderTest extends TestCase
{
    use UsesAProbeDatabase;

    private string $originalConnection;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProbeDatabase('billing_eligibility_order');
        $this->originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('billing_eligibility_order');
        Schema::clearResolvedInstance('db.schema');
        $this->createComponentSchema();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic eligibility', 'slug' => 'synthetic-eligibility']);
        $this->company = $this->company('one');
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalConnection);
        Schema::clearResolvedInstance('db.schema');
        $this->dropProbeDatabase();
        parent::tearDown();
    }

    public function test_only_outer_commit_orders_events_and_savepoint_rollback_discards_its_registrations(): void
    {
        $order = app(BillingEligibilityOrder::class);
        $ids = DB::transaction(function () use ($order): array {
            $first = $order->record($this->workspace->id, $this->company->id);
            try {
                DB::transaction(function () use ($order): void {
                    $order->record($this->workspace->id, $this->company->id);
                    throw new RuntimeException('Synthetic savepoint rollback');
                });
            } catch (RuntimeException $exception) {
                self::assertSame('Synthetic savepoint rollback', $exception->getMessage());
            }
            $second = DB::transaction(fn (): int => $order->record($this->workspace->id, $this->company->id));
            self::assertSame([], DB::table('client_billing_eligibility_events')->pluck('sequence')->all());
            self::assertSame([null, null], array_map(static fn ($position) => $position->sequence, array_values(
                $order->positions($this->workspace->id, $this->company->id, [$first, $second]),
            )));
            self::assertSame(0, $this->counter());

            return [$first, $second];
        });
        self::assertSame($ids, DB::table('client_billing_eligibility_events')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        self::assertSame([1, 2], $this->sequences());
        self::assertSame(2, $this->counter());
        self::assertSame(0, DB::table('billing_eligibility_event_reservations')->count());
        DB::transaction(fn (): int => $order->record($this->workspace->id, $this->company->id));
        self::assertSame([1, 2, 3], $this->sequences());
    }

    public function test_outer_rollback_and_a_later_retry_leave_no_event_or_counter_reservation(): void
    {
        $order = app(BillingEligibilityOrder::class);
        $abandoned = null;
        try {
            DB::transaction(function () use ($order, &$abandoned): void {
                $abandoned = $order->record($this->workspace->id, $this->company->id);
                throw new RuntimeException('Synthetic outer rollback');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic outer rollback', $exception->getMessage());
        }
        self::assertSame([], $this->sequences());
        self::assertSame(0, $this->counter());
        self::assertIsInt($abandoned);
        self::assertSame([], $order->positions($this->workspace->id, $this->company->id, [$abandoned]), 'Rolled-back work has no eligibility even before another allocation reuses its ID.');
        DB::transaction(fn (): int => $order->record($this->workspace->id, $this->company->id));
        self::assertSame([1], $this->sequences());
    }

    public function test_a_failing_commit_listener_rolls_back_business_writes_before_throwing(): void
    {
        DB::table('client_billing_eligibility_counters')->where('workspace_id', $this->workspace->id)->where('client_company_id', $this->company->id)->delete();
        try {
            DB::transaction(function (): void {
                $this->company->forceFill(['name' => 'Synthetic must roll back'])->save();
                app(BillingEligibilityOrder::class)->record($this->workspace->id, $this->company->id);
            });
            self::fail('A missing counter must refuse the physical commit.');
        } catch (LogicException $exception) {
            self::assertSame('The billing eligibility counter is missing or exhausted.', $exception->getMessage());
        }
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        self::assertSame(0, DB::transactionLevel());
        self::assertSame('Synthetic one', $this->company->fresh()?->name);
        self::assertSame([], $this->sequences());
        DB::table('client_billing_eligibility_counters')->insert(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'sequence' => 0]);
        DB::transaction(fn (): int => app(BillingEligibilityOrder::class)->record($this->workspace->id, $this->company->id));
        self::assertSame([1], $this->sequences());
    }

    public function test_company_fences_are_sorted_and_only_owned_event_and_counter_writes_follow(): void
    {
        $second = $this->company('two');
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });
        DB::transaction(function () use ($second): void {
            $order = app(BillingEligibilityOrder::class);
            $order->record($this->workspace->id, $second->id);
            $order->record($this->workspace->id, $this->company->id);
        });
        $companies = [];
        $fenced = false;
        foreach ($queries as $query) {
            $sql = str_replace(['`', '"'], '', $query->sql);
            if (str_starts_with($sql, 'select * from client_billing_eligibility_counters ')) {
                $companies[] = (int) $query->bindings[1];
                $fenced = true;
            } elseif ($fenced && preg_match('/^(insert|update|delete) /', $sql)) {
                if (str_starts_with($sql, 'delete from billing_eligibility_event_reservations ')) {
                    continue;
                }
                self::assertMatchesRegularExpression('/^update client_billing_eligibility_(events|counters) set /', $sql);
                self::assertContains($this->workspace->id, $query->bindings);
            }
        }
        self::assertSame([$this->company->id, $second->id], $companies);
        self::assertSame([1, 1], $this->sequences());
    }

    public function test_committed_child_survives_a_later_sibling_rollback_without_leaking_abandoned_work(): void
    {
        $order = app(BillingEligibilityOrder::class);
        $kept = DB::transaction(function () use ($order): int {
            $kept = DB::transaction(fn (): int => $order->record($this->workspace->id, $this->company->id));
            $abandoned = null;
            try {
                DB::transaction(function () use ($order, &$abandoned): void {
                    $abandoned = $order->record($this->workspace->id, $this->company->id);
                    throw new RuntimeException('Synthetic sibling rollback');
                });
            } catch (RuntimeException $exception) {
                self::assertSame('Synthetic sibling rollback', $exception->getMessage());
            }
            self::assertIsInt($abandoned);
            $positions = $order->positions($this->workspace->id, $this->company->id, [$kept, $abandoned]);
            self::assertArrayHasKey($kept, $positions, 'A child commit promoted this work before its sibling rolled back.');
            self::assertNull($positions[$kept]->sequence);
            self::assertArrayNotHasKey($abandoned, $positions, 'An abandoned sibling cannot masquerade as pending work.');

            return $kept;
        });
        self::assertSame([$kept], DB::table('client_billing_eligibility_events')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        self::assertSame([1], $this->sequences());
        self::assertSame(1, $this->counter());
        self::assertSame(0, DB::table('billing_eligibility_event_reservations')->count());
    }

    private function createComponentSchema(): void
    {
        // Only the coordinator's parents and the two pointer-bearing tables are
        // needed. Its final tables, defaults, and composite FKs come from the
        // actual migration, without replaying unrelated application migrations.
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::create('billing_maintenance_locks', fn (Blueprint $table) => $table->unsignedBigInteger('workspace_id')->primary());
        Schema::create('client_companies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('public_id');
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'id']);
        });
        Schema::create('client_time_entries', fn (Blueprint $table) => $table->id());
        Schema::create('client_invoices', fn (Blueprint $table) => $table->id());
        $migration = require database_path('migrations/2026_11_19_000000_order_billing_eligibility_events.php');
        $migration->up();
    }

    private function company(string $suffix): ClientCompany
    {
        return ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic '.$suffix, 'slug' => 'synthetic-'.$suffix]);
    }

    public function test_replacing_a_connection_cannot_borrow_its_abandoned_pending_positions(): void
    {
        $order = app(BillingEligibilityOrder::class);
        DB::beginTransaction();
        $id = $order->record($this->workspace->id, $this->company->id);
        self::assertArrayHasKey($id, $order->positions($this->workspace->id, $this->company->id, [$id]));
        // Disconnect rolls PDO's transaction back without Laravel's rollback
        // event. A replacement session may reuse the same configured name.
        DB::purge('billing_eligibility_order');
        self::assertSame(0, DB::transactionLevel());
        self::assertSame([], $order->positions($this->workspace->id, $this->company->id, [$id]));
    }

    private function counter(): int
    {
        return (int) DB::table('client_billing_eligibility_counters')->where('workspace_id', $this->workspace->id)
            ->where('client_company_id', $this->company->id)->value('sequence');
    }

    /** @return list<int> */
    private function sequences(): array
    {
        return DB::table('client_billing_eligibility_events')->where('workspace_id', $this->workspace->id)
            ->orderBy('id')->pluck('sequence')->map(static fn ($sequence): int => (int) $sequence)->all();
    }
}
