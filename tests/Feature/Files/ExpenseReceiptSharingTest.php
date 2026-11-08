<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\CallsNativeFileApi;
use Tests\TestCase;

/**
 * The operator side of client-shared receipts: a manager sees and sets the
 * "shared with the client" flag where receipts are managed, nobody else can, a
 * receipt is created private, and changing it is workspace-scoped.
 */
final class ExpenseReceiptSharingTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;
    use CallsNativeFileApi;

    private string $policyDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyDirectory = sys_get_temp_dir().'/svc-receipt-sharing-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->policyDirectory, 0700);
        File::put($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($this->policyDirectory));
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files', 'svc.attachments.sharing_writes_enabled' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->policyDirectory);
        parent::tearDown();
    }

    /** @return array{0: Workspace, 1: ClientCompany, 2: ClientExpense, 3: User, 4: ClientAttachment} */
    private function fixture(): array
    {
        $workspace = $this->syntheticWorkspace('receipt sharing');
        $manager = $this->syntheticMember($workspace, 'manager', 'owner');
        $company = $this->syntheticCompany($workspace, 'receipt sharing');
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $receipt = app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('synthetic-receipt.pdf', 'Synthetic receipt content'), $manager);

        return [$workspace, $company, $expense, $manager, $receipt];
    }

    /** @return array<string, mixed> */
    private function payload(ClientAttachment $receipt, bool $shared, mixed $expense): array
    {
        return ['is_visible_to_client' => $shared, 'expected_version' => AgentApiVersion::for($receipt), 'expected_parent_version' => AgentApiVersion::for($expense)];
    }

    public function test_an_uploaded_receipt_is_private_whatever_the_privacy_latch_says(): void
    {
        [, , , , $receipt] = $this->fixture();

        $this->assertFalse($receipt->is_visible_to_client);
        $this->assertFalse($receipt->fresh()->is_visible_to_client);
    }

    public function test_the_receipts_page_offers_a_labelled_control_and_it_changes_the_flag(): void
    {
        [$workspace, $company, $expense, $manager, $receipt] = $this->fixture();
        $url = route('svc.expenses.receipts', [$workspace, $company, $expense->public_id]);

        $this->actingAs($manager, 'web')->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('files', 1)
            ->where('files.0.sharing.is_visible_to_client', false)
            ->where('files.0.sharing.effective_visible_to_client', false)
            ->where('files.0.sharing.sharing_note', 'Clients can open this receipt only once the expense is billed on an invoice they can see.')
            ->where('files.0.sharing.update_href', route('agent-api.v1.attachments.visibility.update', [$workspace, $receipt->public_id], absolute: false)));

        $this->nativeFileJson('PATCH', route('agent-api.v1.attachments.visibility.update', [$workspace, $receipt->public_id]), $this->payload($receipt, true, $expense))
            ->assertOk()->assertJsonPath('data.is_visible_to_client', true);
        $this->assertTrue($receipt->fresh()->is_visible_to_client);

        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('files.0.sharing.is_visible_to_client', true));

        $shared = $receipt->fresh();
        $this->nativeFileJson('PATCH', route('agent-api.v1.attachments.visibility.update', [$workspace, $receipt->public_id]), $this->payload($shared, false, $expense))->assertOk();
        $this->assertFalse($receipt->fresh()->is_visible_to_client);
    }

    public function test_the_page_offers_no_control_while_file_sharing_is_not_rolled_out(): void
    {
        [$workspace, $company, $expense, $manager, $receipt] = $this->fixture();
        config(['svc.attachments.sharing_writes_enabled' => false]);

        $this->actingAs($manager, 'web')->get(route('svc.expenses.receipts', [$workspace, $company, $expense->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('files', 1)->where('files.0.sharing', null));
        $this->assertFalse($receipt->fresh()->is_visible_to_client);
    }

    public function test_the_effective_flag_says_when_the_expense_is_billed_on_a_visible_invoice(): void
    {
        [$workspace, $company, $expense, $manager, $receipt] = $this->fixture();
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'INV-SYN-SHARE-1', 'status' => 'issued',
            'currency' => 'USD', 'is_visible_to_client' => true,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'type' => 'expense', 'description' => 'Synthetic expense',
            'quantity' => '1', 'unit_amount' => 12500, 'tax_amount' => 0, 'total_amount' => 12500, 'sort_order' => 0,
        ]);
        $expense->forceFill(['client_invoice_line_id' => $line->id])->save();
        $this->actingAs($manager, 'web')->nativeFileJson('PATCH', route('agent-api.v1.attachments.visibility.update', [$workspace, $receipt->public_id]), $this->payload($receipt, true, $expense->fresh()))->assertOk();

        $this->get(route('svc.expenses.receipts', [$workspace, $company, $expense->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('files.0.sharing.effective_visible_to_client', true)->where('files.0.sharing.sharing_note', null));
    }

    public function test_only_a_current_workspace_manager_of_the_receipts_workspace_can_change_it(): void
    {
        [$workspace, , $expense, , $receipt] = $this->fixture();
        $member = $this->syntheticMember($workspace, 'plain member', 'member');
        $foreignOwner = $this->syntheticMember($this->syntheticWorkspace('foreign'), 'foreign owner', 'owner');
        $client = $this->syntheticUser('client');
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $expense->client_company_id, 'user_id' => $client->id, 'role' => 'client', 'access_scope' => 'company']);
        $url = route('agent-api.v1.attachments.visibility.update', [$workspace, $receipt->public_id]);

        foreach (['member' => [$member, 403], 'other workspace' => [$foreignOwner, 404], 'client' => [$client, 403]] as $who => [$outsider, $status]) {
            $this->actingAs($outsider, 'web')->nativeFileJson('PATCH', $url, $this->payload($receipt, true, $expense))->assertStatus($status);
            $this->assertFalse($receipt->fresh()->is_visible_to_client, $who);
        }
    }

    public function test_a_stale_version_is_refused_and_leaves_the_flag_alone(): void
    {
        [$workspace, , $expense, $manager, $receipt] = $this->fixture();
        $payload = $this->payload($receipt, true, $expense);
        $payload['expected_version'] = str_repeat('0', 64);

        $this->actingAs($manager, 'web')->nativeFileJson('PATCH', route('agent-api.v1.attachments.visibility.update', [$workspace, $receipt->public_id]), $payload)->assertStatus(409);
        $this->assertFalse($receipt->fresh()->is_visible_to_client);
    }
}
