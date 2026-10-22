<?php

namespace Tests\Feature\Tasks\Planning;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskGraphState;
use App\Models\ClientTaskLabel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentTaskPlanningMutationAction;
use App\Services\Billing\InvoiceLineComposer;
use App\Services\Tasks\Organization\TaskOrganizationRepository;
use App\Services\Tasks\Planning\TaskPlanningCommand;
use App\Services\Tasks\Planning\TaskPlanningMutationAction;
use App\Services\Tasks\TaskMutationOrigin;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockOrderRecorder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class TaskPlanningMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_off_is_independent_of_organization_and_retains_no_state_or_receipt(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        config(['task_organization.enabled' => true, 'task_planning_details.enabled' => false]);
        $this->expectExceptionStatus(404, fn () => app(AgentTaskPlanningMutationAction::class)->execute($owner, $workspace, 'synthetic-client', 'synthetic-key', 'labels.create', null, ['name' => 'Synthetic label']));
        $this->assertDatabaseCount('client_task_labels', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $this->assertSame(AgentApiVersion::for($task), AgentApiVersion::for($task->fresh()));
    }

    public function test_label_catalog_uniqueness_archive_retention_clear_and_new_key_noop_versions(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $label = $this->label($workspace, $owner, 'Équipe');
        $this->expectValidation(fn () => $this->label($workspace, $owner, "\u{00a0}éQUIPE\u{3000}"));
        $before = $task->lock_version;
        $this->replace($owner, $workspace, $project, $task, 'labels.replace', ['label_ids' => [$label->public_id]]);
        $this->assertSame($before + 1, $task->fresh()->lock_version);
        $this->assertSame(1, $this->stateVersion($workspace, $project));
        $this->perform($owner, $workspace, $label->public_id, 'labels.archive', ['expected_version' => AgentApiVersion::for($label), 'confirm' => true]);
        $this->replace($owner, $workspace, $project, $task, 'labels.replace', ['label_ids' => [$label->public_id]]);
        $this->assertSame(2, $this->stateVersion($workspace, $project));
        $this->assertDatabaseCount('client_task_label_assignments', 1);
        $this->replace($owner, $workspace, $project, $task, 'labels.replace', ['label_ids' => []]);
        $this->expectValidation(fn () => $this->replace($owner, $workspace, $project, $task, 'labels.replace', ['label_ids' => [$label->public_id]]));
        $this->assertSame(3, $this->stateVersion($workspace, $project));
        $this->assertDatabaseCount('client_task_label_assignments', 0);
        $this->assertTrue($label->fresh()->is_archived);
        $this->perform($owner, $workspace, $label->public_id, 'labels.restore', ['expected_version' => AgentApiVersion::for($label->fresh())]);
        $this->perform($owner, $workspace, $label->public_id, 'labels.update', ['expected_version' => AgentApiVersion::for($label->fresh()), 'name' => 'Synthetic renamed label']);
        $this->assertSame('Synthetic renamed label', $label->fresh()->name);
    }

    public function test_dependencies_advance_changed_endpoints_reject_cycles_and_preserve_stale_graph_protection(): void
    {
        [$workspace, $project, $owner, $first] = $this->fixture();
        $second = $this->task($workspace, $project, 'Synthetic prerequisite');
        $third = $this->task($workspace, $project, 'Synthetic unrelated');
        $oldGraph = $this->graphVersion($workspace, $project);
        $secondVersion = $second->lock_version;
        $thirdVersion = $third->lock_version;
        $this->replace($owner, $workspace, $project, $first, 'dependencies.replace', ['prerequisite_ids' => [$second->public_id]]);
        $this->assertSame($secondVersion + 1, $second->fresh()->lock_version);
        $this->assertSame($thirdVersion, $third->fresh()->lock_version);
        $this->expectExceptionStatus(409, fn () => $this->perform($owner, $workspace, $first->public_id, 'dependencies.replace',
            ['prerequisite_ids' => [], 'expected_version' => AgentApiVersion::for($first->fresh()), 'expected_graph_version' => $oldGraph]));
        $this->expectValidation(fn () => $this->replace($owner, $workspace, $project, $second, 'dependencies.replace', ['prerequisite_ids' => [$first->public_id]]));
        $this->assertDatabaseCount('client_task_dependencies', 1);
        $this->assertSame(1, $this->stateVersion($workspace, $project));
        $this->replace($owner, $workspace, $project, $first, 'dependencies.replace', ['prerequisite_ids' => []]);
        $this->assertSame($secondVersion + 2, $second->fresh()->lock_version);
        $this->assertDatabaseCount('client_task_dependencies', 0);
    }

    public function test_contacts_remain_distinct_archived_and_company_scoped_without_grants_or_lifecycle_changes(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $task->forceFill(['status' => 'completed', 'completed_at' => '2026-10-01 12:00:00', 'milestone_price_amount' => 12345])->save();
        $before = $task->fresh()->getAttributes();
        $contact = $this->contact($workspace, $project);
        $grantTables = ['users', 'workspace_memberships', 'client_project_memberships', 'client_company_memberships', 'client_portal_invitations', 'client_invoices', 'client_invoice_lines'];
        $counts = array_map(fn (string $table): int => DB::table($table)->count(), $grantTables);
        $this->replace($owner, $workspace, $project, $task, 'contacts.replace', ['contact_ids' => [$contact->public_id]]);
        $contact->forceFill(['is_archived' => true])->save();
        $this->replace($owner, $workspace, $project, $task, 'contacts.replace', ['contact_ids' => [$contact->public_id]]);
        $this->assertDatabaseCount('client_task_contact_responsibilities', 1);
        $this->replace($owner, $workspace, $project, $task, 'contacts.replace', ['contact_ids' => []]);
        $this->expectValidation(fn () => $this->replace($owner, $workspace, $project, $task, 'contacts.replace', ['contact_ids' => [$contact->public_id]]));
        $this->assertTrue($contact->fresh()->is_archived);
        $contact->forceFill(['is_archived' => false])->save();
        $this->replace($owner, $workspace, $project, $task, 'contacts.replace', ['contact_ids' => [$contact->public_id]]);
        $this->assertSame($counts, array_map(fn (string $table): int => DB::table($table)->count(), $grantTables));
        $after = $task->fresh()->getAttributes();
        foreach (['lock_version', 'updated_at'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('client_task_assignments', 0);
    }

    public function test_project_managers_can_attach_labels_but_cannot_change_catalog_or_business_contact_responsibility(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $manager = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        $membership = ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $label = $this->label($workspace, $owner);
        $contact = $this->contact($workspace, $project);
        $this->replace($manager, $workspace, $project, $task, 'labels.replace', ['label_ids' => [$label->public_id]]);
        $this->expectExceptionStatus(403, fn () => $this->label($workspace, $manager));
        $this->expectExceptionStatus(403, fn () => $this->replace($manager, $workspace, $project, $task, 'contacts.replace', ['contact_ids' => [$contact->public_id]]));
        $membership->forceFill(['role' => 'viewer'])->save();
        $this->expectExceptionStatus(403, fn () => $this->replace($manager, $workspace, $project, $task, 'labels.replace', ['label_ids' => []]));
        $this->assertDatabaseCount('client_task_label_assignments', 1);
        $this->assertDatabaseCount('client_task_contact_responsibilities', 0);
    }

    public function test_planning_changes_preserve_a_claimed_milestone_without_rebilling_or_completing_prerequisites(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $task->forceFill(['status' => 'completed', 'completed_at' => '2026-10-01 12:00:00', 'milestone_price_amount' => 18750])->save();
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id,
            'invoice_number' => 'SYNTHETIC-PLANNING-1', 'currency' => 'USD', 'status' => 'draft', 'service_period_end' => '2026-10-31']);
        $sort = 0;
        DB::transaction(function () use ($project, $invoice, &$sort): void {
            app(InvoiceLineComposer::class)->addBillableMilestoneTasks($project->clientCompany, $invoice, Carbon::parse('2026-10-31'), $sort);
        });
        $task = $task->fresh();
        $claim = $task->client_invoice_line_id;
        $this->assertNotNull($claim);
        $prerequisite = $this->task($workspace, $project, 'Synthetic unfinished prerequisite');
        $label = $this->label($workspace, $owner);
        $contact = $this->contact($workspace, $project);
        $this->replace($owner, $workspace, $project, $task, 'labels.replace', ['label_ids' => [$label->public_id]]);
        $this->replace($owner, $workspace, $project, $task, 'dependencies.replace', ['prerequisite_ids' => [$prerequisite->public_id]]);
        $this->replace($owner, $workspace, $project, $task, 'contacts.replace', ['contact_ids' => [$contact->public_id]]);
        DB::transaction(function () use ($project, $invoice, &$sort): void {
            app(InvoiceLineComposer::class)->addBillableMilestoneTasks($project->clientCompany, $invoice, Carbon::parse('2026-11-30'), $sort);
        });
        $this->assertSame($claim, $task->fresh()->client_invoice_line_id);
        $this->assertSame('2026-10-01 12:00:00', $task->fresh()->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame(18750, $task->fresh()->milestone_price_amount);
        $this->assertSame('open', $prerequisite->fresh()->status);
        $this->assertNull($prerequisite->fresh()->completed_at);
        $this->assertSame(18750, $invoice->lines()->sole()->unit_amount);
        $this->assertSame('2026-10-01', $invoice->lines()->sole()->line_date->toDateString());
        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertDatabaseCount('client_invoice_lines', 1);
    }

    public function test_foreign_task_label_contact_and_prerequisite_never_cross_scope(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        [$foreignWorkspace, $foreignProject, $foreignOwner, $foreignTask] = $this->fixture('foreign');
        $foreignLabel = $this->label($foreignWorkspace, $foreignOwner);
        $foreignContact = $this->contact($foreignWorkspace, $foreignProject);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other company', 'slug' => 'synthetic-other-company']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic other project']);
        $otherContact = $this->contact($workspace, $otherProject);
        $otherTask = $this->task($workspace, $otherProject, 'Synthetic other task');
        $this->expectExceptionStatus(404, fn () => $this->replace($owner, $workspace, $project, $foreignTask, 'labels.replace', ['label_ids' => []]));
        foreach ([['labels.replace', ['label_ids' => [$foreignLabel->public_id]]], ['contacts.replace', ['contact_ids' => [$foreignContact->public_id]]],
            ['contacts.replace', ['contact_ids' => [$otherContact->public_id]]], ['dependencies.replace', ['prerequisite_ids' => [$foreignTask->public_id]]],
            ['dependencies.replace', ['prerequisite_ids' => [$otherTask->public_id]]]] as [$operation, $input]) {
            $this->expectValidation(fn () => $this->replace($owner, $workspace, $project, $task, $operation, $input));
        }
        foreach (['client_task_label_assignments', 'client_task_dependencies', 'client_task_contact_responsibilities', 'client_task_graph_states'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_shared_receipt_replay_rechecks_dual_identity_and_preserves_native_origin_and_revisions(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $label = $this->label($workspace, $owner);
        $input = ['label_ids' => [$label->public_id], 'expected_version' => AgentApiVersion::for($task)];
        $writes = app(AgentTaskPlanningMutationAction::class);
        $id = $writes->execute($owner, $workspace, 'synthetic-client', 'synthetic-key', 'labels.replace', $task->public_id, $input);
        $version = AgentApiVersion::for($task->fresh());
        $this->assertSame($id, $writes->execute($owner, $workspace, 'synthetic-client', 'synthetic-key', 'labels.replace', $task->public_id, $input));
        $this->assertSame($version, AgentApiVersion::for($task->fresh()));
        $this->assertSame(1, $this->stateVersion($workspace, $project));
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'user_id' => $owner->id, 'role' => 'client']);
        $this->expectExceptionStatus(403, fn () => $writes->execute($owner, $workspace, 'synthetic-client', 'synthetic-key', 'labels.replace', $task->public_id, $input));
        $this->expectExceptionStatus(403, fn () => $writes->execute($owner, $workspace, 'synthetic-client', 'synthetic-new-key', 'labels.replace', $task->public_id,
            ['label_ids' => [], 'expected_version' => $version]));
        $writes->executeInternal($owner, $workspace, 'synthetic-native', 'synthetic-native-key', 'labels.replace', $task->public_id, ['label_ids' => [], 'expected_version' => $version]);
        $this->assertDatabaseCount('agent_mutation_receipts', 2);
        $this->assertDatabaseCount('client_task_label_assignments', 0);
    }

    public function test_registered_planning_lock_sequences_stay_forward(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $label = $this->label($workspace, $owner);
        $contact = $this->contact($workspace, $project);
        foreach ([['labels.replace', ['label_ids' => [$label->public_id]]], ['contacts.replace', ['contact_ids' => [$contact->public_id]]], ['dependencies.replace', ['prerequisite_ids' => []]]] as [$operation, $input]) {
            LockOrderRecorder::start();
            try {
                $this->replace($owner, $workspace, $project, $task, $operation, $input);
                foreach (LockOrderRecorder::sequences() as $sequence) {
                    $ranks = array_map(fn ($resource): int => $resource->rank(), $sequence);
                    $sorted = $ranks;
                    sort($sorted);
                    $this->assertSame($sorted, $ranks, $operation);
                }
            } finally {
                LockOrderRecorder::stop();
            }
        }
    }

    public function test_structural_graph_caps_do_not_block_labels_or_contact_metadata_on_large_legacy_projects(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $rows = [];
        for ($index = 0; $index < 1000; $index++) {
            $rows[] = ['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
                'public_id' => (string) Str::uuid(), 'title' => 'Synthetic retained flat task '.$index];
        }
        ClientTask::query()->insert($rows);
        $label = $this->label($workspace, $owner);
        $contact = $this->contact($workspace, $project);
        $this->replace($owner, $workspace, $project, $task, 'labels.replace', ['label_ids' => [$label->public_id]]);
        $this->replace($owner, $workspace, $project, $task, 'contacts.replace', ['contact_ids' => [$contact->public_id]]);
        $this->expectValidation(fn () => $this->perform($owner, $workspace, $task->public_id, 'dependencies.replace',
            ['prerequisite_ids' => [], 'expected_version' => AgentApiVersion::for($task->fresh()), 'expected_graph_version' => str_repeat('g', 64)]));
        $this->assertDatabaseCount('client_task_label_assignments', 1);
        $this->assertDatabaseCount('client_task_contact_responsibilities', 1);
        $this->assertDatabaseCount('client_task_dependencies', 0);
        $this->assertSame(2, $this->stateVersion($workspace, $project));
    }

    public function test_malformed_task_parenthood_blocks_dependency_writers_without_disclosing_ancestry(): void
    {
        [$workspace, $project, $owner, $first] = $this->fixture();
        $second = $this->task($workspace, $project, 'Synthetic cyclic parent');
        $first->forceFill(['parent_task_id' => $second->id])->save();
        $second->forceFill(['parent_task_id' => $first->id])->save();
        try {
            $this->perform($owner, $workspace, $first->public_id, 'dependencies.replace', ['prerequisite_ids' => [],
                'expected_version' => AgentApiVersion::for($first->fresh()), 'expected_graph_version' => str_repeat('g', 64)]);
            $this->fail('Invalid parenthood was accepted by a dependency writer.');
        } catch (ValidationException $exception) {
            $this->assertSame(['planning' => ['The task organization is invalid.']], $exception->errors());
            $this->assertStringNotContainsString($second->public_id, $exception->getMessage());
            $this->assertStringNotContainsString($second->title, $exception->getMessage());
        }
        $this->assertDatabaseCount('client_task_dependencies', 0);
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    private function fixture(string $suffix = 'local'): array
    {
        config(['task_planning_details.enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic planning '.$suffix, 'slug' => 'synthetic-planning-'.$suffix]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company '.$suffix, 'slug' => 'synthetic-planning-company-'.$suffix]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project '.$suffix]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $project, $owner, $this->task($workspace, $project, 'Synthetic planning task')];
    }

    private function task(Workspace $workspace, ClientProject $project, string $title): ClientTask
    {
        return ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => $title]);
    }

    private function contact(Workspace $workspace, ClientProject $project): ClientContact
    {
        return ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'first_name' => 'Synthetic', 'last_name' => 'Responsible contact', 'email' => 'synthetic-responsibility@example.test']);
    }

    private function label(Workspace $workspace, User $actor, string $name = 'Synthetic label'): ClientTaskLabel
    {
        $id = $this->perform($actor, $workspace, null, 'labels.create', ['name' => $name]);

        return ClientTaskLabel::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->sole();
    }

    private function replace(User $actor, Workspace $workspace, ClientProject $project, ClientTask $task, string $operation, array $input): string
    {
        $input += ['expected_version' => AgentApiVersion::for($task->fresh())];
        if ($operation === 'dependencies.replace') {
            $input['expected_graph_version'] = $this->graphVersion($workspace, $project);
        }

        return $this->perform($actor, $workspace, $task->public_id, $operation, $input);
    }

    private function perform(User $actor, Workspace $workspace, ?string $target, string $operation, array $input): string
    {
        return app(TaskPlanningMutationAction::class)->perform($actor, $workspace, $target, TaskPlanningCommand::fromInput($operation, $input), TaskMutationOrigin::Internal);
    }

    private function graphVersion(Workspace $workspace, ClientProject $project): string
    {
        $repository = app(TaskOrganizationRepository::class);

        return $repository->graph($repository->load($workspace, $project, false))->graphVersion();
    }

    private function stateVersion(Workspace $workspace, ClientProject $project): int
    {
        return ClientTaskGraphState::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)->sole()->lock_version;
    }

    private function expectExceptionStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a refused operation.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a rejected planning input.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
