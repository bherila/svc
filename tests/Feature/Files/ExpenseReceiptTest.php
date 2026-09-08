<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

class ExpenseReceiptTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
    }

    public function test_manager_can_upload_list_download_and_remove_a_receipt_without_changing_expense(): void
    {
        $workspace = $this->syntheticWorkspace('receipts');
        $manager = $this->syntheticMember($workspace, 'manager');
        $company = $this->syntheticCompany($workspace, 'receipts');
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $url = route('svc.expenses.receipts', [$workspace, $company, $expense->public_id]);
        $this->actingAs($manager)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('clients/expense-receipts')->has('files', 0));
        $this->postJson(route('svc.files.store', [$workspace, 'expense', $expense->public_id]), [
            'file' => UploadedFile::fake()->createWithContent('synthetic-receipt.txt', 'Synthetic receipt content'),
        ])->assertCreated()->assertJsonPath('record_type', 'expense');
        $attachment = ClientAttachment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertStringContainsString('/expense/'.$expense->public_id.'/', $attachment->object_key);
        $this->assertStringNotContainsString('synthetic-receipt.txt', $attachment->object_key);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('files', 1)->where('files.0.filename', 'synthetic-receipt.txt'));
        $download = route('svc.files.download', [$workspace, $attachment->public_id]);
        $this->get($download)->assertOk()->assertStreamedContent('Synthetic receipt content');
        $this->deleteJson(route('svc.files.destroy', [$workspace, $attachment->public_id]))->assertStatus(202);
        $this->get($download)->assertNotFound();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('files', 0));
        $this->assertSame('draft', $expense->fresh()->status);
        $this->assertSame(12500, $expense->fresh()->amount);
    }

    public function test_stale_parent_refusal_compensates_staged_bytes(): void
    {
        $workspace = $this->syntheticWorkspace('stale receipt parent');
        $manager = $this->syntheticMember($workspace, 'manager');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'stale'));
        (new WorkspaceExpenses($workspace))->discard($expense);
        try {
            app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('stale.txt', 'Synthetic stale receipt'), $manager);
            $this->fail('A stale expense instance must not publish a receipt.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, ClientAttachment::query()->where('workspace_id', $workspace->id)->count());
            $this->assertSame([], Storage::disk('svc_files')->allFiles());
        }
    }

    public function test_failed_publication_rolls_back_the_row_and_compensates_promoted_bytes(): void
    {
        $workspace = $this->syntheticWorkspace('failed receipt publication');
        $manager = $this->syntheticMember($workspace, 'manager');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'failure'));
        $failed = false;
        DB::listen(function (QueryExecuted $event) use (&$failed): void {
            if (! $failed && str_starts_with(strtolower($event->sql), 'update') && str_contains($event->sql, 'client_attachments') && in_array('available', $event->bindings, true)) {
                $failed = true;
                throw new \RuntimeException('Synthetic publication failure');
            }
        });
        try {
            app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('failed.txt', 'Synthetic failed receipt'), $manager);
            $this->fail('The publication failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic publication failure', $exception->getMessage());
            $this->assertTrue($failed);
            $this->assertSame(0, ClientAttachment::query()->where('workspace_id', $workspace->id)->count());
            $this->assertSame([], Storage::disk('svc_files')->allFiles());
        }
    }

    public function test_complete_upload_download_and_delete_requests_scope_every_tenant_query(): void
    {
        $workspace = $this->syntheticWorkspace('query trace');
        $manager = $this->syntheticMember($workspace, 'manager');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'trace'));
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $sql = str_replace(['"', '`'], '', strtolower($event->sql));
            if (preg_match('/^(?:select .*? from|update|insert into|delete from) ([a-z_]+)/', $sql, $match) === 1) {
                $queries[] = [$match[1], $sql];
            }
        });
        $response = $this->actingAs($manager)->postJson(route('svc.files.store', [$workspace, 'expense', $expense->public_id]), ['file' => UploadedFile::fake()->createWithContent('trace.txt', 'synthetic')])->assertCreated();
        $id = $response->json('id');
        $this->get(route('svc.files.download', [$workspace, $id]))->assertOk();
        $this->deleteJson(route('svc.files.destroy', [$workspace, $id]))->assertStatus(202);
        $this->assertContains('client_attachments', array_column($queries, 0));
        $this->assertContains('client_expenses', array_column($queries, 0));
        $this->assertContains('client_companies', array_column($queries, 0));
        foreach ($queries as [$table, $sql]) {
            // Identity roots have no workspace_id column.
            if (in_array($table, ['users', 'workspaces'], true)) {
                continue;
            }
            if (str_starts_with($sql, 'insert into')) {
                $this->assertStringContainsString('workspace_id', strstr($sql, 'values', true), $sql);
            } else {
                $where = strstr($sql, ' where ');
                $this->assertNotFalse($where, $sql);
                $this->assertMatchesRegularExpression('/(?:\\b[a-z_]+\\.)?workspace_id\\s*=/', $where, $sql);
            }
        }
    }

    public function test_receipts_are_denied_to_members_and_portal_users_even_on_generic_download(): void
    {
        $workspace = $this->syntheticWorkspace('private receipts');
        $manager = $this->syntheticMember($workspace, 'manager');
        $member = $this->syntheticMember($workspace, 'member', 'member');
        $portal = $this->syntheticUser('portal');
        $company = $this->syntheticCompany($workspace, 'private');
        $company->portalUsers()->attach($portal->id, ['role' => 'client']);
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $attachment = app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('private.txt', 'private synthetic receipt'), $manager);
        foreach ([$member, $portal] as $user) {
            $this->actingAs($user)->get(route('svc.expenses.receipts', [$workspace, $company, $expense->public_id]))->assertForbidden();
            $this->get(route('svc.files.download', [$workspace, $attachment->public_id]))->assertForbidden();
            $this->deleteJson(route('svc.files.destroy', [$workspace, $attachment->public_id]))->assertForbidden();
            $this->postJson(route('svc.files.store', [$workspace, 'expense', $expense->public_id]), ['file' => UploadedFile::fake()->createWithContent('denied.txt', 'denied')])->assertForbidden();
        }
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $attachment->fresh()->lifecycle_state);
    }

    public function test_a_tenant_owned_attachment_cannot_point_to_a_foreign_expense(): void
    {
        $workspace = $this->syntheticWorkspace('corrupt receipt');
        $manager = $this->syntheticMember($workspace, 'manager');
        $company = $this->syntheticCompany($workspace, 'home');
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $foreign = $this->syntheticWorkspace('foreign parent');
        $foreignExpense = $this->recordSyntheticExpense($foreign, $this->syntheticCompany($foreign, 'foreign'));
        $receipt = app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('corrupt.txt', 'synthetic'), $manager);
        DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $receipt->id)->update(['record_public_id' => $foreignExpense->public_id]);
        $this->actingAs($manager)->get(route('svc.files.download', [$workspace, $receipt->public_id]))->assertNotFound();
        $this->deleteJson(route('svc.files.destroy', [$workspace, $receipt->public_id]))->assertNotFound();
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $receipt->fresh()->lifecycle_state);
    }

    public function test_foreign_company_foreign_workspace_and_discarded_parents_are_refused(): void
    {
        $workspace = $this->syntheticWorkspace('home receipts');
        $manager = $this->syntheticMember($workspace, 'manager');
        $company = $this->syntheticCompany($workspace, 'home');
        $otherCompany = $this->syntheticCompany($workspace, 'other');
        $foreign = $this->syntheticWorkspace('foreign receipts');
        $foreignCompany = $this->syntheticCompany($foreign, 'foreign');
        $foreignExpense = $this->recordSyntheticExpense($foreign, $foreignCompany);
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $attachment = app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('parent.txt', 'synthetic'), $manager);
        $this->actingAs($manager)->get(route('svc.expenses.receipts', [$workspace, $otherCompany, $expense->public_id]))->assertNotFound();
        $this->get(route('svc.expenses.receipts', [$workspace, $company, $foreignExpense->public_id]))->assertNotFound();
        $this->postJson(route('svc.files.store', [$workspace, 'expense', $foreignExpense->public_id]), ['file' => UploadedFile::fake()->createWithContent('foreign.txt', 'synthetic')])->assertNotFound();
        $expense->delete();
        $this->get(route('svc.expenses.receipts', [$workspace, $company, $expense->public_id]))->assertNotFound();
        $this->get(route('svc.files.download', [$workspace, $attachment->public_id]))->assertNotFound();
        $this->deleteJson(route('svc.files.destroy', [$workspace, $attachment->public_id]))->assertNotFound();
        $this->postJson(route('svc.files.store', [$workspace, 'expense', $expense->public_id]), ['file' => UploadedFile::fake()->createWithContent('discarded.txt', 'synthetic')])->assertNotFound();
    }
}
