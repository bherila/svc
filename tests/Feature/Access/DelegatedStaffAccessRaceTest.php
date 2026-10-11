<?php

namespace Tests\Feature\Access;

use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Access\DelegatedStaffAccess;
use App\Support\AgentApi\ProjectRole;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * A delegated removal racing a concurrent assignment, on a real second
 * connection.
 *
 * The removal runs inside a transaction whose snapshot predates an assignment
 * another connection commits. Compared against that snapshot, the revision the
 * provider confirmed would still match, and the removal - whose own delete is a
 * locking read and sees the new row - would take an assignment it never
 * disclosed. The revision is compared under the workspace access lock with
 * locking reads, so it sees the new assignment and refuses.
 */
final class DelegatedStaffAccessRaceTest extends TestCase
{
    use BuildsAccessFixtures;
    use UsesAProbeDatabase;

    private const PROVIDER = 'example-provider';

    public function test_an_assignment_committed_after_the_callers_snapshot_is_never_taken_undisclosed(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The snapshot race requires MariaDB.');
        }
        $this->bootProbeDatabase('staff_race');
        Artisan::call('migrate', ['--database' => 'staff_race', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'staff_race', 'bherila-auth.oauth_client.provider' => self::PROVIDER]);
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
            $workspace = Workspace::query()->create(['name' => 'Synthetic staff race', 'slug' => 'synthetic-staff-race']);
            $manager = User::factory()->create(['oauth_provider' => self::PROVIDER, 'oauth_subject' => 'manager-subject']);
            $target = User::factory()->create(['oauth_provider' => self::PROVIDER, 'oauth_subject' => 'target-subject']);
            $this->addStaff($workspace, $manager, 'owner');
            $this->addStaff($workspace, $target, 'member');
            $company = $this->company($workspace, 'synthetic-client');
            $alpha = $this->project($company, 'Alpha');
            $beta = $this->project($company, 'Beta');
            $this->assign($target, $alpha, ProjectRole::Manager);

            $adapter = app(DelegatedStaffAccess::class);
            $state = $adapter->handle('manager-subject', ['operation' => 'read', 'subject' => 'target-subject']);
            $this->assertSame(1, $state['access']['workspaces'][0]['removal_effects'][0]['count']);

            $refusal = null;
            DB::transaction(function () use ($adapter, $state, $workspace, $target, $beta, &$refusal): void {
                // Takes this transaction's snapshot, before the other connection commits.
                $this->assertSame(1, ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $target->id)->count());
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Access/staff-assignment-race-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode([
                        'connection' => config('database.connections.staff_race'), 'workspace' => $workspace->id,
                        'project' => $beta->id, 'user' => $target->id,
                    ], JSON_THROW_ON_ERROR)."\n", 20);
                $worker->mustRun();
                $this->assertSame("assigned\n", $worker->getOutput());
                // The snapshot still shows one assignment.
                $this->assertSame(1, ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $target->id)->count());

                try {
                    $adapter->handle('manager-subject', ['operation' => 'remove', 'subject' => 'target-subject',
                        'expected_revision' => $state['revision'], 'operation_id' => DelegatedContract::operationId()]);
                } catch (DelegatedAccessException $exception) {
                    $refusal = $exception->outcome;
                }
            });

            $this->assertSame('revision_conflict', $refusal);
            DB::purge('staff_race');
            $this->assertSame('member', WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $target->id)->value('role'));
            $this->assertSame(2, ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $target->id)->count());
        } finally {
            config(['database.default' => $original]);
        }
    }
}
