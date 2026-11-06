<?php

namespace Tests\Feature\Files;

use App\Models\ClientAgreement;
use App\Models\ClientCompanyMembership;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentPageBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AttachmentPaginationTest extends TestCase
{
    use BuildsSyntheticExpenses, CallsMcp, RefreshDatabase;

    /** @return list<array{string}> */
    public static function kinds(): array
    {
        return [['generic'], ['expense']];
    }

    #[DataProvider('kinds')]
    public function test_all_rows_are_reachable_in_existing_descending_order_and_large_unicode_names_fit_mcp(string $kind): void
    {
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
        $workspace = $this->syntheticWorkspace('file-pagination');
        $owner = $this->syntheticMember($workspace, 'file-pagination-owner', 'owner');
        $company = $this->syntheticCompany($workspace, 'file-pagination');
        $record = $kind === 'generic' ? $this->syntheticProject($company, 'file-pagination') : $this->recordSyntheticExpense($workspace, $company);
        $first = app(AttachmentStorageService::class)->store($workspace, $record,
            UploadedFile::fake()->createWithContent('synthetic-file-0.txt', 'Synthetic page bytes'), $owner);
        $template = (array) DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $first->id)->first();
        unset($template['id']);
        $expected = [$first->public_id];
        for ($index = 1; $index < 105; $index++) {
            $id = (string) Str::uuid();
            DB::table('client_attachments')->insert([...$template, 'public_id' => $id, 'object_key' => 'synthetic/'.Str::uuid(),
                'original_filename' => Crypt::encryptString('synthetic-file-'.$index.'.txt')]);
            $expected[] = $id;
        }
        $this->actingAsMcp($owner, ['mcp:use', 'files:read', 'expenses:read']);
        $base = '/api/v1/workspaces/'.$workspace->public_id;
        $url = $kind === 'generic' ? $base.'/records/project/'.$record->public_id.'/attachments' : $base.'/expenses/'.$record->public_id.'/receipts';
        $page = $this->getJson($url)->assertOk()->assertJsonCount(100, 'data')->json();
        $this->assertEquals(array_slice(array_reverse($expected), 0, 100), array_column($page['data'], 'id'));
        $this->assertNotNull($page['meta']['next_cursor']);
        $second = $this->getJson($url.'?cursor='.rawurlencode($page['meta']['next_cursor']))->assertOk()->assertJsonCount(5, 'data')->json();
        $this->assertSame(null, $second['meta']['next_cursor']);
        $this->assertSame(array_reverse($expected), [...array_column($page['data'], 'id'), ...array_column($second['data'], 'id')]);
        $this->getJson($url.'?limit=101')->assertUnprocessable();
        $this->getJson($url.'?limit=0')->assertUnprocessable();
        $this->getJson($url.'?cursor='.rawurlencode(base64_encode('17')))->assertUnprocessable();
        $this->assertSame(17, AgentApiCursor::decode(base64_encode('17'), $workspace->public_id, 'legacy-existing-query'), 'Old callers retain the default legacy cursor interpretation.');

        // Real encrypted TEXT values valid on MariaDB, with full filenames kept.
        $longName = 'Synthetic-'.str_repeat('🔒', 3000).'.txt';
        DB::table('client_attachments')->where('workspace_id', $workspace->id)->update(['original_filename' => Crypt::encryptString($longName)]);
        $tool = $kind === 'generic' ? 'attachments.list' : 'expenses.receipts.list';
        $arguments = $kind === 'generic' ? ['workspace_id' => $workspace->public_id, 'record_type' => 'project', 'record_id' => $record->public_id]
            : ['workspace_id' => $workspace->public_id, 'expense_id' => $record->public_id];
        $session = $this->initialize();
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $response = $this->callTool($session, $tool, [...$arguments, ...($cursor === null ? [] : ['cursor' => $cursor])]);
            $this->assertFalse($response['result']['isError'] ?? false, json_encode($response, JSON_THROW_ON_ERROR));
            $content = $response['result']['structuredContent'];
            $this->assertNotEmpty($content['data']);
            $this->assertLessThanOrEqual(AgentPageBudget::dataBytes(), strlen(json_encode($content['data'], JSON_THROW_ON_ERROR)));
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($response['result'], JSON_THROW_ON_ERROR)));
            foreach ($content['data'] as $item) {
                $this->assertSame($longName, $item['filename']);
                $seen[] = $item['id'];
            }
            $cursor = $content['meta']['next_cursor'];
            $this->assertLessThan(20, ++$pages);
        } while ($cursor !== null);
        $this->assertGreaterThan(2, $pages, 'Byte budget must shorten pages below their100-row limit.');
        $this->assertSame(array_reverse($expected), $seen);
    }

    public function test_opaque_cursors_bind_parent_tenant_actor_and_audience_and_native_pages_have_continuation(): void
    {
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
        $workspace = $this->syntheticWorkspace('bound-file-pagination');
        $owner = $this->syntheticMember($workspace, 'bound-file-owner', 'owner');
        $company = $this->syntheticCompany($workspace, 'bound-file-pagination');
        $project = $this->syntheticProject($company, 'bound-file-pagination');
        $project->update(['is_visible_to_client' => true]);
        for ($index = 0; $index < 2; $index++) {
            app(AttachmentStorageService::class)->store($workspace, $project, UploadedFile::fake()->createWithContent('synthetic-bound-'.$index.'.txt', 'Synthetic bound bytes'), $owner);
        }
        $this->actingAsMcp($owner, ['files:read']);
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/records/';
        $path = 'project/'.$project->public_id.'/attachments';
        $cursor = $this->getJson($base.$path.'?limit=1')->assertOk()->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $tail = '?cursor='.rawurlencode($cursor);
        $other = $this->syntheticProject($company, 'other-parent');
        $this->getJson($base.'project/'.$other->public_id.'/attachments'.$tail)->assertUnprocessable();
        $otherActor = $this->syntheticMember($workspace, 'other-file-owner', 'admin');
        $this->actingAsMcp($otherActor, ['files:read']);
        $this->getJson($base.$path.$tail)->assertUnprocessable();
        $otherWorkspace = $this->syntheticWorkspace('other-cursor-tenant');
        $otherWorkspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $otherCompany = $this->syntheticCompany($otherWorkspace, 'other-cursor-tenant');
        $otherProject = $this->syntheticProject($otherCompany, 'other-cursor-tenant');
        $this->actingAsMcp($owner, ['files:read']);
        $this->getJson('/api/v1/workspaces/'.$otherWorkspace->public_id.'/records/project/'.$otherProject->public_id.'/attachments'.$tail)->assertUnprocessable();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'client', 'access_scope' => 'company']);
        $this->getJson($base.$path.$tail)->assertUnprocessable();

        $expense = $this->recordSyntheticExpense($workspace, $company);
        $file = app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('synthetic-native-0.txt', 'Synthetic native bytes'), $owner);
        $row = (array) DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $file->id)->first();
        unset($row['id']);
        for ($index = 0; $index < 100; $index++) {
            DB::table('client_attachments')->insert([...$row, 'public_id' => (string) Str::uuid(), 'object_key' => 'synthetic/'.Str::uuid()]);
        }
        $response = $this->actingAs($owner)->get(route('svc.expenses.receipts', [$workspace, $company, $expense->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('files', 100)->where('files_previous_href', null));
        $next = $response->viewData('page')['props']['files_next_href'];
        $this->assertStringContainsString('files_page=2', $next);
        $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page->has('files', 1)->where('files_next_href', null));
    }

    public function test_agreement_route_families_and_operations_supply_finished_continuation_links(): void
    {
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
        $workspace = $this->syntheticWorkspace('native-file-pages');
        $owner = $this->syntheticMember($workspace, 'native-file-owner', 'owner');
        $company = $this->syntheticCompany($workspace, 'native-file-pages');
        $client = $this->syntheticUser('native-file-client');
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'user_id' => $client->id, 'role' => 'client', 'access_scope' => 'company']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'title' => 'Synthetic paged agreement', 'starts_on' => '2026-10-01', 'currency' => 'USD', 'status' => 'active', 'is_visible_to_client' => true]);
        $file = app(AttachmentStorageService::class)->store($workspace, $agreement,
            UploadedFile::fake()->createWithContent('synthetic-native-file.txt', 'Synthetic native bytes'), $owner);
        $row = (array) DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $file->id)->first();
        unset($row['id']);
        for ($index = 0; $index < 300; $index++) {
            DB::table('client_attachments')->insert([...$row, 'public_id' => (string) Str::uuid(), 'object_key' => 'synthetic/'.Str::uuid()]);
        }
        foreach ([[$owner, route('clients.agreement', [$workspace, $company, $agreement])], [$client, route('portal.agreement', [$company, $agreement])]] as [$actor, $url]) {
            $response = $this->actingAs($actor)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('files', 100)->where('files_previous_href', null));
            $next = $response->viewData('page')['props']['files_next_href'];
            $this->assertStringStartsWith('/', $next);
            $this->assertStringContainsString('files_page=2#files', $next);
            $second = $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page->has('files', 100));
            $previous = $second->viewData('page')['props']['files_previous_href'];
            $this->assertStringStartsWith('/', $previous);
            $this->get($previous)->assertOk()->assertInertia(fn (Assert $page) => $page->has('files', 100)->where('files_previous_href', null));
        }
        $operations = $this->actingAs($owner)->get(route('workspaces.operations', $workspace))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('workspace.clients.0.agreements.0.attachments', 300)->where('workspace.files_previous_href', null));
        $next = $operations->viewData('page')['props']['workspace']['files_next_href'];
        $this->assertStringContainsString('files_page=2#files-pagination', $next);
        $this->get($next)->assertOk()->assertInertia(fn (Assert $page) => $page->has('workspace.clients.0.agreements.0.attachments', 1)->where('workspace.files_next_href', null));
    }
}
