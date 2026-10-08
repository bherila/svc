<?php

namespace Tests\Unit\Actions;

use App\Actions\CreateWorkspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreateWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $checks = 0;
        DB::listen(function (QueryExecuted $query) use (&$checks): void {
            if (str_contains($query->sql, 'exists') && str_contains($query->sql, 'workspaces')) {
                // These fixtures need at most seven candidate checks. Fail a
                // repeated-candidate loop directly, rather than timing out.
                $this->assertLessThanOrEqual(7, ++$checks);
            }
        });
    }

    public function test_it_allocates_suffixes_and_gives_only_the_requested_actor_ownership(): void
    {
        $owner = User::factory()->create();
        $action = app(CreateWorkspace::class);
        $first = $action->handle($owner, 'Synthetic Slug');
        $second = $action->handle($owner, 'Synthetic Slug');
        $third = $action->handle($owner, 'Synthetic Slug');
        $this->assertSame(['synthetic-slug', 'synthetic-slug-2', 'synthetic-slug-3'], [$first->slug, $second->slug, $third->slug]);
        $this->assertSame('Synthetic Slug', $third->name);
        $this->assertSame('owner', $third->memberships()->where('user_id', $owner->id)->sole()->role);
        $this->assertDatabaseCount('workspace_memberships', 3);
        $this->assertSame('workspace', $action->handle($owner, '!!!')->slug);
    }

    /** @return iterable<string, array{string}> */
    public static function slugConstraints(): iterable
    {
        yield 'SQLite' => ['UNIQUE constraint failed: workspaces.slug'];
        yield 'MariaDB' => ["Duplicate entry 'synthetic-slug' for key 'workspaces_slug_unique'"];
        yield 'PostgreSQL' => ['duplicate key value violates unique constraint "workspaces_slug_unique"'];
    }

    #[DataProvider('slugConstraints')]
    public function test_slug_insert_collisions_advance_the_candidate_and_leave_the_transaction_usable(string $constraint): void
    {
        $owner = User::factory()->create();
        $fired = false;
        $this->failFirstInsert($constraint, $fired);
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Synthetic Slug');
        $this->assertTrue($fired);
        $this->assertSame('synthetic-slug-2', $workspace->slug);
        $this->assertSame('owner', $workspace->memberships()->where('user_id', $owner->id)->sole()->role);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('workspace_memberships', 1);
    }

    /** @return iterable<string, array{string|int|null}> */
    public static function unrelatedConstraints(): iterable
    {
        yield 'other unique column' => ['UNIQUE constraint failed: workspaces.public_id'];
        yield 'missing driver detail' => [null];
        yield 'invalid driver detail' => [19];
    }

    #[DataProvider('unrelatedConstraints')]
    public function test_unrelated_uniqueness_failures_are_not_retried_or_partially_persisted(string|int|null $constraint): void
    {
        $owner = User::factory()->create();
        $fired = false;
        $this->failFirstInsert($constraint, $fired);
        try {
            app(CreateWorkspace::class)->handle($owner, 'Synthetic Slug');
            $this->fail('An unrelated unique constraint must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($constraint, $exception->errorInfo[2]);
        }
        $this->assertTrue($fired);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_memberships', 0);
    }

    private function failFirstInsert(string|int|null $constraint, bool &$fired): void
    {
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($constraint, &$fired): void {
            if (! $fired && preg_match('/^insert\s+into\s+[`"]?workspaces[`"]?\s/i', $sql) === 1) {
                $fired = true;
                $driver = new PDOException('Synthetic uniqueness fault');
                $driver->errorInfo = ['23000', 19, $constraint];
                throw new UniqueConstraintViolationException(DB::connection()->getName(), $sql, $bindings, $driver);
            }
        });
    }
}
