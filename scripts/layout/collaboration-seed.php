<?php

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CommentDraft;
use App\Services\Collaboration\TopicDraft;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentSharingTransport;
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
$sharingLayout = getenv('SVC_LAYOUT_ATTACHMENT_SHARING') === 'true';
if ($sharingLayout) {
    file_put_contents($runtime.'/storage/app/private/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
}
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
    for ($index = 1; $index <= ($sharingLayout ? 3 : 32); $index++) {
        $comment = $action->createComment($manager, $workspace, $company, 'topic', $topic->public_id,
            CommentDraft::fromInput(['body' => 'Synthetic reply '.$index."\n".str_repeat('SyntheticCommentBody', 30), 'is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($topic)]), CollaborationAudience::Internal);
        if ($index === 2) {
            $action->deleteComment($manager, $workspace, $company, $comment->public_id, AgentApiVersion::for($comment), CollaborationAudience::Internal);
        }
    }
}
$task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => str_repeat('SyntheticTask', 12), 'status' => 'pending']);
$action->createComment($manager, $workspace, $company, 'task', $task->public_id, CommentDraft::fromInput(['body' => 'Synthetic task comment', 'expected_version' => AgentApiVersion::for($task)]), CollaborationAudience::Internal);
// Explicit historical-reader fixtures below retain legacy-public flags; real uploads remain private.
$agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'client_project_id' => $project->id, 'title' => str_repeat('SyntheticAgreement', 10), 'starts_on' => '2026-10-01',
    'currency' => 'USD', 'status' => 'active', 'is_visible_to_client' => true]);
$portalProposal = ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'client_project_id' => $project->id, 'title' => str_repeat('SyntheticProposal', 12), 'status' => 'sent', 'currency' => 'USD', 'is_visible_to_client' => true]);
$portalInvoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'invoice_number' => 'SYNTHETIC-SHARED-FILES', 'status' => 'issued', 'currency' => 'USD', 'is_visible_to_client' => true]);
$expense = (new WorkspaceExpenses($workspace))->record($company, $project,
    new NewExpense(CarbonImmutable::parse('2026-10-01'), 10000, 'USD', str_repeat('SyntheticReceiptExpense', 10)), $manager);
$storage = app(AttachmentStorageService::class);
foreach ([[$agreement, $sharingLayout ? 4 : 301], [$expense, $sharingLayout ? 1 : 101], [$portalProposal, 2], [$portalInvoice, 2], [$project, 2], [$task, 2]] as [$parent, $count]) {
    $first = $storage->store($workspace, $parent, UploadedFile::fake()->createWithContent('SyntheticReaderFile.txt', 'Synthetic browser file bytes'), $manager);
    DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $first->id)->update(['is_visible_to_client' => true]);
    $row = (array) DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $first->id)->first();
    unset($row['id']);
    for ($index = 1; $index < $count; $index++) {
        $id = (string) Str::uuid();
        $key = 'synthetic-reader/'.$id;
        $filename = $index === $count - 1 ? str_repeat('SyntheticLongUnbrokenFilename', 15).'.txt' : 'SyntheticFile-'.$index.'.txt';
        DB::table('client_attachments')->insert([...$row, 'public_id' => $id, 'object_key' => $key,
            'original_filename' => Crypt::encryptString($filename), 'is_visible_to_client' => ! ($parent instanceof ClientProposal || $parent instanceof ClientInvoice || $parent instanceof ClientProject || $parent instanceof ClientTask)]);
        Storage::disk('svc_files')->put($key, 'Synthetic browser file bytes');
    }
}
if ($sharingLayout) {
    $transport = app(AttachmentSharingTransport::class);
    foreach ($topics as $topic) {
        $comment = ClientComment::query()->where('workspace_id', $workspace->id)->where('client_collaboration_topic_id', $topic->id)->whereNull('deleted_at')->firstOrFail();
        foreach ([new AttachmentParentReference('topic', $topic->public_id, $company->public_id),
            new AttachmentParentReference('comment', $comment->public_id, $company->public_id)] as $reference) {
            $parent = $reference->type === 'topic' ? $topic : $comment;
            foreach (range(1, 4) as $index) {
                $filename = $index === 1 ? 'SyntheticPrivateFile.txt' : str_repeat('SyntheticLongFile', 15).'-'.$index.'.txt';
                $file = $transport->upload($manager, $workspace, $reference, UploadedFile::fake()->createWithContent($filename, 'Synthetic sharing browser bytes'),
                    ['expected_version' => AgentApiVersion::for($parent)], 'synthetic-browser', 'upload-'.$reference->publicId.'-'.$index, AttachmentMutationOrigin::Native)['data'];
                if ($index !== 1) {
                    $transport->updateVisibility($manager, $workspace, $reference, $file['id'], ['is_visible_to_client' => true,
                        'expected_version' => $file['version'], 'expected_parent_version' => AgentApiVersion::for($parent)],
                        'synthetic-browser', 'share-'.$file['id'], AttachmentMutationOrigin::Native);
                }
            }
        }
    }
}
file_put_contents($runtime.'/fixture.json', json_encode(['user_id' => $manager->id, 'portal_user_id' => $portal->id,
    'company_name' => $company->name,
    'attachment_visibility' => route('agent-api.v1.attachments.visibility.update', [$workspace, ClientAttachment::query()->where('workspace_id', $workspace->id)->where('record_type', 'agreement')->where('record_public_id', $agreement->public_id)->orderBy('id')->firstOrFail()->public_id], absolute: false),
    'portalprojectfiles' => route('portal.files.parent.show', [$company, 'project', $project->public_id], absolute: false),
    'portaltaskfiles' => route('portal.files.parent.show', [$company, 'task', $task->public_id], absolute: false),
    'portalproposalfiles' => route('portal.proposal', [$company, $portalProposal], absolute: false),
    'portalinvoicefiles' => route('portal.invoice', [$company, $portalInvoice], absolute: false),
    'parentfiles' => route('svc.files.collaboration.show', [$workspace, $company->public_id, 'topic', $topics['note']->public_id], absolute: false),
    'dual_preview_user_id' => $dualPreview->id,
    'agreementfiles' => route('clients.agreement', [$workspace, $company, $agreement], absolute: false),
    'portalagreementfiles' => route('portal.agreement', [$company, $agreement], absolute: false),
    'nativeportalagreementfiles' => route('portal.agreement', [$company, $agreement], absolute: false),
    'dualportalagreementfiles' => route('portal.agreement', [$company, $agreement], absolute: false),
    'expensefiles' => route('svc.expenses.receipts', [$workspace, $company, $expense->public_id], absolute: false),
    'operationsfiles' => route('workspaces.operations', $workspace, absolute: false),
    'taskthread' => route('collaboration.comments.index', [$workspace, $company, 'task', $task->public_id], absolute: false),
    'content_topic_create' => route('agent-api.v1.collaboration.topics.store', [$workspace, $company->public_id], absolute: false),
    'content_comment_create' => route('agent-api.v1.collaboration.comments.store', [$workspace, $company->public_id, 'topic', $topics['note']->public_id], absolute: false),
    'index' => route('collaboration.index', [$workspace, $company, 'kind' => 'note'], absolute: false),
    'note' => route('collaboration.show', [$workspace, $company, $topics['note']], absolute: false),
    'discussion' => route('collaboration.show', [$workspace, $company, $topics['discussion']], absolute: false),
    'portal' => route('portal.collaboration.show', [$company, $topics['discussion']], absolute: false)], JSON_THROW_ON_ERROR));
