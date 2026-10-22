<?php

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CommentDraft;
use App\Services\Collaboration\TopicDraft;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
$dualPreview = User::factory()->create(['email' => 'collaboration-layout-dual-preview@synthetic.test']);
$workspace->memberships()->create(['user_id' => $dualPreview->id, 'role' => 'admin']);
$company->portalUsers()->attach($dualPreview->id, ['role' => 'viewer', 'access_scope' => 'projects']);
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
$task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => str_repeat('SyntheticTask', 12), 'status' => 'pending']);
$action->createComment($manager, $workspace, $company, 'task', $task->public_id, CommentDraft::fromInput(['body' => 'Synthetic task comment', 'expected_version' => AgentApiVersion::for($task)]), CollaborationAudience::Internal);
// Reader-only future/private rows are synthetic. Every real uploader remains R1 legacy-true.
$agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'client_project_id' => $project->id, 'title' => str_repeat('SyntheticAgreement', 10), 'starts_on' => '2026-10-01',
    'currency' => 'USD', 'status' => 'active', 'is_visible_to_client' => true]);
$expense = (new WorkspaceExpenses($workspace))->record($company, $project,
    new NewExpense(CarbonImmutable::parse('2026-10-01'), 10000, 'USD', str_repeat('SyntheticReceiptExpense', 10)), $manager);
$storage = app(AttachmentStorageService::class);
foreach ([[$agreement, 301], [$expense, 101]] as [$parent, $count]) {
    $first = $storage->store($workspace, $parent, UploadedFile::fake()->createWithContent('SyntheticReaderFile.txt', 'Synthetic browser file bytes'), $manager);
    $row = (array) DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $first->id)->first();
    unset($row['id']);
    for ($index = 1; $index < $count; $index++) {
        $id = (string) Str::uuid();
        $key = 'synthetic-reader/'.$id;
        $filename = $index === $count - 1 ? str_repeat('SyntheticLongUnbrokenFilename', 15).'.txt' : 'SyntheticFile-'.$index.'.txt';
        DB::table('client_attachments')->insert([...$row, 'public_id' => $id, 'object_key' => $key,
            'original_filename' => Crypt::encryptString($filename)]);
        Storage::disk('svc_files')->put($key, 'Synthetic browser file bytes');
    }
}
file_put_contents($runtime.'/fixture.json', json_encode(['user_id' => $manager->id, 'portal_user_id' => $portal->id,
    'company_name' => $company->name,
    'dual_preview_user_id' => $dualPreview->id,
    'agreementfiles' => route('clients.agreement', [$workspace, $company, $agreement], absolute: false),
    'portalagreementfiles' => route('portal.agreement', [$company, $agreement], absolute: false),
    'nativeportalagreementfiles' => route('portal.agreement', [$company, $agreement], absolute: false),
    'dualportalagreementfiles' => route('portal.agreement', [$company, $agreement], absolute: false),
    'expensefiles' => route('svc.expenses.receipts', [$workspace, $company, $expense->public_id], absolute: false),
    'operationsfiles' => route('workspaces.operations', $workspace, absolute: false),
    'taskthread' => route('collaboration.comments.index', [$workspace, $company, 'task', $task->public_id], absolute: false),
    'index' => route('collaboration.index', [$workspace, $company, 'kind' => 'note'], absolute: false),
    'note' => route('collaboration.show', [$workspace, $company, $topics['note']], absolute: false),
    'discussion' => route('collaboration.show', [$workspace, $company, $topics['discussion']], absolute: false),
    'portal' => route('portal.collaboration.show', [$company, $topics['discussion']], absolute: false)], JSON_THROW_ON_ERROR));
