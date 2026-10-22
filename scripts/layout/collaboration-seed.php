<?php

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CommentDraft;
use App\Services\Collaboration\TopicDraft;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Artisan;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Synthetic collaboration fixtures require CLI.');
}
$runtime = getenv('SVC_LAYOUT_RUNTIME');
$app = require __DIR__.'/bootstrap.php';
if (! is_string($runtime) || filesize($runtime.'/database.sqlite') !== 0) {
    throw new RuntimeException('An empty disposable SQLite database is required.');
}
Artisan::call('migrate', ['--force' => true]);
$manager = User::factory()->create(['name' => str_repeat('SyntheticAuthor', 13), 'email' => 'collaboration-layout@synthetic.test']);
$workspace = Workspace::query()->create(['name' => 'Synthetic Collaboration Workspace', 'slug' => 'synthetic-collaboration-layout']);
$workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
$company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => str_repeat('SyntheticCompany', 12), 'slug' => 'synthetic-collaboration-company']);
$project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => str_repeat('SyntheticProject', 12), 'is_visible_to_client' => true]);
$privateProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic private project', 'is_visible_to_client' => false]);
$portal = User::factory()->create(['email' => 'collaboration-layout-portal@synthetic.test']);
$company->portalUsers()->attach($portal->id, ['role' => 'viewer', 'access_scope' => 'company']);
$action = app(CollaborationAction::class);
$topics = [];
foreach (['note', 'discussion'] as $kind) {
    $topic = $action->createTopic($manager, $workspace, $company, TopicDraft::fromInput(['kind' => $kind,
        'title' => str_repeat('Synthetic'.ucfirst($kind), 12), 'body' => '<script>Literal synthetic text</script>'."\n".str_repeat('SyntheticUnbrokenBody', 120),
        'project_id' => ($kind === 'note' ? $privateProject : $project)->public_id, 'is_visible_to_client' => true]), CollaborationAudience::Internal);
    $topics[$kind] = $topic;
    for ($index = 1; $index <= 32; $index++) {
        $comment = $action->createComment($manager, $workspace, $company, 'topic', $topic->public_id,
            CommentDraft::fromInput(['body' => 'Synthetic reply '.$index."\n".str_repeat('SyntheticCommentBody', 30), 'is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($topic)]), CollaborationAudience::Internal);
        if ($index === 2) {
            $action->deleteComment($manager, $workspace, $company, $comment->public_id, AgentApiVersion::for($comment), CollaborationAudience::Internal);
        }
    }
}
file_put_contents($runtime.'/fixture.json', json_encode(['user_id' => $manager->id, 'portal_user_id' => $portal->id,
    'index' => route('collaboration.index', [$workspace, $company], absolute: false),
    'note' => route('collaboration.show', [$workspace, $company, $topics['note']], absolute: false),
    'discussion' => route('collaboration.show', [$workspace, $company, $topics['discussion']], absolute: false),
    'portal' => route('portal.collaboration.show', [$company, $topics['discussion']], absolute: false)], JSON_THROW_ON_ERROR));
