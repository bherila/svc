<?php

namespace Tests\Concerns;

use App\Models\ClientAttachment;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SharedProjects\SharedProjectParticipations;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Support\Str;

/**
 * Synthetic hosts, participants and the secrets none of them may read.
 *
 * Every string beginning SECRET- belongs to a record a given viewer must never
 * be told about; every string beginning VISIBLE- is a control that proves the
 * surface was not empty for the wrong reason.
 */
trait BuildsSharedProjects
{
    /** @var array<string, mixed> */
    protected array $world = [];

    protected function workspace(string $name): Workspace
    {
        return Workspace::query()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'default_currency' => 'USD']);
    }

    protected function person(Workspace $workspace, string $role, string $label): User
    {
        $user = User::factory()->create(['name' => $label, 'email' => Str::slug($label).'-'.Str::lower(Str::random(6)).'@example.test']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function hold(ClientProject $project, User $user, ProjectRole $role): void
    {
        ClientProjectMembership::query()->create(['workspace_id' => $project->workspace_id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $role->value]);
    }

    protected function task(ClientProject $project, string $title, bool $visible = true, ?User $assignee = null, ?Workspace $assigneeWorkspace = null): ClientTask
    {
        $task = ClientTask::query()->create(['workspace_id' => $project->workspace_id, 'client_project_id' => $project->id, 'title' => $title, 'description' => 'Details of '.$title, 'status' => 'open', 'is_visible_to_client' => $visible, 'milestone_price_amount' => 424242]);
        if ($assignee !== null) {
            $this->assignment($task, $assignee, $assigneeWorkspace);
        }

        return $task;
    }

    protected function assignment(ClientTask $task, User $assignee, ?Workspace $assigneeWorkspace = null): ClientTaskAssignment
    {
        $assignment = new ClientTaskAssignment;
        $assignment->forceFill(['workspace_id' => $task->workspace_id, 'client_task_id' => $task->id, 'user_id' => $assignee->id, 'assignee_workspace_id' => $assigneeWorkspace?->id])->save();

        return $assignment;
    }

    protected function comment(ClientTask $task, User $author, string $body, bool $shared, ?Workspace $authorWorkspace = null): ClientComment
    {
        $comment = new ClientComment;
        $comment->forceFill([
            'workspace_id' => $task->workspace_id, 'client_company_id' => $task->project->client_company_id, 'client_project_id' => $task->client_project_id,
            'client_task_id' => $task->id, 'body' => $body, 'author_user_id' => $author->id, 'author_name' => $author->name,
            'is_visible_to_client' => false, 'shared_with_participants' => $shared, 'author_workspace_id' => $authorWorkspace?->id,
        ])->save();

        return $comment;
    }

    /** Invite an address to the project and have the given workspace accept, as the real flow does. */
    protected function join(Workspace $host, User $hostOwner, ClientProject $project, User $invitee, Workspace $participant): void
    {
        $participations = app(SharedProjectParticipations::class);
        $invitation = $participations->invite($hostOwner, $host, $project->public_id, (string) $invitee->email);
        $participations->accept($invitee, $invitation->public_id, $participant);
    }

    /**
     * A host with a shared project and two participants, each with a local
     * project of its own, and everything private around them.
     */
    protected function sharedWorld(): void
    {
        $host = $this->workspace('Synthetic Host Studio');
        $a = $this->workspace('Synthetic Partner Alpha');
        $b = $this->workspace('Synthetic Partner Beta');
        $stranger = $this->workspace('Synthetic Stranger Co');

        $hostOwner = $this->person($host, 'owner', 'Hollis Hostowner');
        $hostManager = $this->person($host, 'member', 'Hana Hostmanager');
        $hostViewer = $this->person($host, 'member', 'Hugo Hostviewer');
        $hostPlain = $this->person($host, 'member', 'Hector Hostplain');
        $aOwner = $this->person($a, 'owner', 'Alma Alphaowner');
        $aAdmin = $this->person($a, 'admin', 'Adel Alphaadmin');
        $aMember = $this->person($a, 'member', 'Aiden Alphamember');
        $aOnLocal = $this->person($a, 'member', 'Anya Alphalocal');
        $bOwner = $this->person($b, 'owner', 'Boris Betaowner');
        $strangerOwner = $this->person($stranger, 'owner', 'Sasha Strangerowner');

        $company = ClientCompany::query()->create(['workspace_id' => $host->id, 'name' => 'SECRET-HOST-CLIENT-NAME', 'slug' => 'secret-host-client']);
        $project = ClientProject::query()->create(['workspace_id' => $host->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Shared Project', 'description' => 'SECRET-HOST-PROJECT-NOTE', 'status' => 'active']);
        $other = ClientProject::query()->create(['workspace_id' => $host->id, 'client_company_id' => $company->id, 'name' => 'SECRET-OTHER-HOST-PROJECT', 'status' => 'active']);
        $this->hold($project, $hostManager, ProjectRole::Manager);
        $this->hold($project, $hostViewer, ProjectRole::Viewer);

        $shared = $this->task($project, 'VISIBLE-SHARED-TASK');
        $internal = $this->task($project, 'SECRET-HOST-INTERNAL-TASK', visible: false);
        $otherTask = $this->task($other, 'SECRET-OTHER-PROJECT-TASK');
        $this->comment($shared, $hostOwner, 'SECRET-HOST-INTERNAL-COMMENT', shared: false);
        $this->comment($shared, $hostOwner, 'VISIBLE-HOST-SHARED-COMMENT', shared: true);

        ClientTimeEntry::query()->create([
            'workspace_id' => $host->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $hostOwner->id,
            'worked_on' => '2026-11-04', 'minutes' => 60, 'description' => 'SECRET-HOST-TIME', 'status' => 'draft',
            'billing_rate_amount' => 987654, 'currency' => 'USD', 'subcontractor_cost_amount' => 765432,
        ]);
        ClientInvoice::query()->create(['workspace_id' => $host->id, 'client_company_id' => $company->id, 'invoice_number' => 'SECRET-HOST-INV-1', 'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 13579, 'tax_amount' => 0, 'total_amount' => 13579, 'balance_amount' => 13579]);
        ClientAttachment::query()->create(['workspace_id' => $host->id, 'record_type' => 'task', 'record_public_id' => $shared->public_id, 'object_key' => 'synthetic/secret-file', 'original_filename' => 'SECRET-HOST-FILE.pdf', 'media_type' => 'application/pdf', 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'uploader_id' => $hostOwner->id, 'lifecycle_state' => ClientAttachment::STATE_AVAILABLE]);

        $this->join($host, $hostOwner, $project, $aOwner, $a);
        $this->join($host, $hostOwner, $project, $bOwner, $b);

        $aCompany = ClientCompany::query()->create(['workspace_id' => $a->id, 'name' => 'SECRET-A-CLIENT-NAME', 'slug' => 'secret-a-client']);
        $aLocal = ClientProject::query()->create(['workspace_id' => $a->id, 'client_company_id' => $aCompany->id, 'name' => 'SECRET-A-LOCAL-PROJECT', 'status' => 'active']);
        $this->hold($aLocal, $aOnLocal, ProjectRole::Contributor);
        app(SharedProjectParticipations::class)->linkLocalProject($aOwner, $a, $project->public_id, $aLocal->public_id);
        ClientTimeEntry::query()->create([
            'workspace_id' => $a->id, 'client_company_id' => $aCompany->id, 'client_project_id' => $aLocal->id, 'user_id' => $aOwner->id,
            'worked_on' => '2026-11-04', 'minutes' => 90, 'description' => 'SECRET-A-TIME', 'status' => 'draft',
            'billing_rate_amount' => 555111, 'currency' => 'USD', 'subcontractor_cost_amount' => 0,
        ]);
        $bCompany = ClientCompany::query()->create(['workspace_id' => $b->id, 'name' => 'SECRET-B-CLIENT-NAME', 'slug' => 'secret-b-client']);
        $bLocal = ClientProject::query()->create(['workspace_id' => $b->id, 'client_company_id' => $bCompany->id, 'name' => 'SECRET-B-LOCAL-PROJECT', 'status' => 'active']);
        app(SharedProjectParticipations::class)->linkLocalProject($bOwner, $b, $project->public_id, $bLocal->public_id);
        ClientTimeEntry::query()->create([
            'workspace_id' => $b->id, 'client_company_id' => $bCompany->id, 'client_project_id' => $bLocal->id, 'user_id' => $bOwner->id,
            'worked_on' => '2026-11-04', 'minutes' => 45, 'description' => 'SECRET-B-TIME', 'status' => 'draft',
            'billing_rate_amount' => 333777, 'currency' => 'USD', 'subcontractor_cost_amount' => 0,
        ]);

        $this->world = compact('host', 'a', 'b', 'stranger', 'hostOwner', 'hostManager', 'hostViewer', 'hostPlain', 'aOwner', 'aAdmin', 'aMember', 'aOnLocal', 'bOwner', 'strangerOwner',
            'company', 'project', 'other', 'shared', 'internal', 'otherTask', 'aLocal', 'bLocal');
    }

    /** Every string that belongs to a record a participant must never be told about. */
    protected function hostSecrets(): array
    {
        return ['SECRET-HOST-CLIENT-NAME', 'SECRET-HOST-PROJECT-NOTE', 'SECRET-OTHER-HOST-PROJECT', 'SECRET-HOST-INTERNAL-TASK', 'SECRET-OTHER-PROJECT-TASK',
            'SECRET-HOST-INTERNAL-COMMENT', 'SECRET-HOST-TIME', 'SECRET-HOST-INV-1', 'SECRET-HOST-FILE', '987654', '765432', '424242', '13579'];
    }
}
