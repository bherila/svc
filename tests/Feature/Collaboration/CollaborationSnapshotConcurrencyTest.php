<?php

namespace Tests\Feature\Collaboration;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationOrigin;
use App\Services\Collaboration\CommentDraft;
use App\Services\Collaboration\ContentPatch;
use App\Services\Collaboration\TopicDraft;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class CollaborationSnapshotConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public static function interleavings(): iterable
    {
        yield 'project authority revoked' => ['project_demoted', 'topic'];
        yield 'company authority revoked' => ['staff_demoted', 'topic'];
        yield 'comment author loses access' => ['project_demoted', 'comment'];
        yield 'receipt replay loses authority' => ['project_demoted', 'replay'];
        yield 'agent gains portal identity' => ['portal_granted', 'topic'];
    }

    #[DataProvider('interleavings')]
    public function test_current_locked_authority_overrides_a_real_old_snapshot(string $operation, string $subject): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A separate-connection snapshot race requires MariaDB.');
        }
        $this->bootProbeDatabase('collaboration_race');
        Artisan::call('migrate', ['--database' => 'collaboration_race', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'collaboration_race']);
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
            $workspace = Workspace::query()->create(['name' => 'Synthetic collaboration race', 'slug' => 'synthetic-collaboration-race']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic race company', 'slug' => 'synthetic-race-company']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic race project']);
            $owner = User::factory()->create();
            $actor = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $workspace->memberships()->create(['user_id' => $actor->id, 'role' => $operation === 'staff_demoted' ? 'admin' : 'member']);
            ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $actor->id, 'role' => 'manager']);
            $action = app(CollaborationAction::class);
            $topic = $action->createTopic($actor, $workspace, $company, TopicDraft::fromInput(['kind' => 'discussion', 'title' => 'Synthetic race topic', 'body' => 'Original synthetic content', 'project_id' => $operation === 'staff_demoted' ? null : $project->public_id]), CollaborationAudience::Internal);
            $comment = $action->createComment($actor, $workspace, $company, 'topic', $topic->public_id, CommentDraft::fromInput(['body' => 'Original synthetic comment', 'expected_version' => AgentApiVersion::for($topic)]), CollaborationAudience::Internal);
            $refusal = null;
            DB::transaction(function () use ($operation, $subject, $workspace, $company, $project, $owner, $actor, $topic, $comment, $action, &$refusal): void {
                $oldStaffRole = $workspace->memberships()->where('user_id', $actor->id)->value('role');
                $this->assertSame('manager', ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->value('role')?->value);
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->count());
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Collaboration/collaboration-race-worker.php')], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode([
                    'connection' => config('database.connections.collaboration_race'), 'workspace' => $workspace->id, 'project' => $project->id,
                    'owner' => $owner->id, 'actor' => $actor->id, 'operation' => $operation,
                ], JSON_THROW_ON_ERROR)."\n", 20);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
                $this->assertSame($oldStaffRole, $workspace->memberships()->where('user_id', $actor->id)->value('role'));
                $this->assertSame('manager', ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->value('role')?->value);
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->count(), 'Ordinary reads still see the pre-commit snapshot.');
                try {
                    if ($subject === 'replay') {
                        $action->assertReplayAllowed($actor, $workspace, $company, 'collaboration.topics.create', $topic->public_id, [], CollaborationOrigin::Agent);
                    } elseif ($subject === 'comment') {
                        $action->updateComment($actor, $workspace, $company, $comment->public_id, ContentPatch::fromInput(['body' => 'Refused synthetic edit', 'expected_version' => AgentApiVersion::for($comment)], false), CollaborationAudience::Internal);
                    } else {
                        $action->updateTopic($actor, $workspace, $company, $topic->public_id, ContentPatch::fromInput(['body' => 'Refused synthetic edit', 'expected_version' => AgentApiVersion::for($topic)], true), CollaborationAudience::Internal, CollaborationOrigin::Agent);
                    }
                } catch (HttpExceptionInterface $exception) {
                    $refusal = $exception->getStatusCode();
                }
            });
            $this->assertSame(403, $refusal);
            $this->assertSame('Original synthetic content', $topic->fresh()->body);
            $this->assertSame('Original synthetic comment', $comment->fresh()->body);
        } finally {
            config(['database.default' => $original]);
        }
    }
}
