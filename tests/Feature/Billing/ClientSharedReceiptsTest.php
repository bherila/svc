<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceDocumentBuilder;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentReader;
use App\Support\Billing\Document\ReceiptDetail;
use App\Support\Billing\InvoiceLineDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\Concerns\BuildsReceiptInvoices;
use Tests\TestCase;

/**
 * A client opens an expense receipt only when ALL hold: the file is shared with
 * the client, the expense is billed on an invoice that client can already see,
 * and a project-scoped contact holds the expense's project. Every case below is
 * a way the answer must stay "no" (404, never a hint of what exists), through
 * the portal receipt route, the portal invoice JSON and the client PDF.
 */
final class ClientSharedReceiptsTest extends TestCase
{
    use BuildsGoldenInvoices;
    use BuildsReceiptInvoices;
    use RefreshDatabase;

    private const PNG_PATTERN = '/Subtype \/Image/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
        $this->fakeReceiptDisk();
    }

    private function client(?ClientCompany $company = null, string $scope = ClientCompanyMembership::SCOPE_COMPANY): User
    {
        $company ??= $this->company;
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'client', 'access_scope' => $scope,
        ]);

        return $user;
    }

    /**
     * A project-scoped contact granted the given projects.
     *
     * @param  list<ClientProject>  $projects
     */
    private function scopedClient(array $projects): User
    {
        $user = $this->client(scope: ClientCompanyMembership::SCOPE_PROJECTS);
        $membership = ClientCompanyMembership::query()->where('user_id', $user->id)->sole();
        foreach ($projects as $project) {
            DB::table('client_portal_project_access')->insert([
                'workspace_id' => $this->workspace->id,
                'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id,
            ]);
        }

        return $user;
    }

    /** @return array{0: ClientInvoice, 1: ClientExpense, 2: ClientAttachment, 3: ClientAttachment} the invoice, its expense, a shared receipt and an unshared one */
    private function billed(?ClientProject $project = null, array $invoice = []): array
    {
        $project ??= $this->project;
        $invoice = $this->goldenInvoice($invoice);
        $this->goldenLine($invoice, ['client_project_id' => $project->id, 'sort_order' => 1]);
        [$expense] = $this->goldenExpense($invoice, 'Synthetic staging database', 41200, '2026-08-12', ['client_project_id' => $project->id], sort: 10);
        $shared = $this->goldenReceipt($expense, 'shared.png', 'image/png', $this->pngBytes());
        $private = $this->goldenReceipt($expense, 'private.png', 'image/png', $this->pngBytes(), visibleToClient: false);

        return [$invoice, $expense, $shared, $private];
    }

    private function open(User $user, ClientExpense $expense, ClientAttachment $file, ?ClientCompany $company = null): TestResponse
    {
        return $this->actingAs($user)->get(route('portal.files.receipt.download', [
            'clientCompany' => ($company ?? $this->company)->public_id, 'recordPublicId' => $expense->public_id, 'attachment' => $file->public_id,
        ]));
    }

    private function portalJson(User $user, ClientInvoice $invoice): TestResponse
    {
        return $this->actingAs($user)->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}");
    }

    private function clientPdf(User $user, ClientInvoice $invoice): TestResponse
    {
        return $this->actingAs($user)->get("/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/pdf");
    }

    private function images(TestResponse $pdf): int
    {
        return (int) preg_match_all(self::PNG_PATTERN, (string) $pdf->getContent());
    }

    public function test_a_client_opens_a_shared_receipt_on_an_invoice_they_can_see_and_not_an_unshared_one(): void
    {
        [, $expense, $shared, $private] = $this->billed();
        $client = $this->client();

        $this->open($client, $expense, $shared)->assertOk();
        $this->open($client, $expense, $private)->assertNotFound();
    }

    public function test_the_same_answer_holds_through_the_portal_invoice_json_and_the_client_pdf(): void
    {
        [$invoice, , , $private] = $this->billed();
        $client = $this->client();

        $this->portalJson($client, $invoice)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('document.receipts.receipt_count', 1)
            ->where('document.receipts.entries.0.files.0.filename', 'shared.png')
            ->missing('document.receipts.entries.0.files.1'));
        $this->assertStringNotContainsString($private->public_id, (string) $this->portalJson($client, $invoice)->getContent());
        $this->assertSame(1, $this->images($this->clientPdf($client, $invoice)->assertOk()));
    }

    public function test_an_unbilled_expenses_receipt_stays_private_even_when_shared(): void
    {
        $unbilled = ClientExpense::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'created_by_user_id' => $this->member->id,
            'spent_on' => '2026-08-03', 'amount' => 800, 'currency' => 'USD', 'description' => 'Synthetic unbilled expense', 'status' => 'approved',
        ]);
        $file = $this->goldenReceipt($unbilled, 'unbilled.png', 'image/png', $this->pngBytes());

        $this->open($this->client(), $unbilled, $file)->assertNotFound();
        $this->assertSame([], $this->readableReceiptIds($this->client(), [$unbilled]));
    }

    /** @return list<string> */
    private function readableReceiptIds(User $viewer, array $expenses): array
    {
        return app(AttachmentReader::class)->queryForTypes($viewer, $this->workspace, AttachmentAudience::Portal, ['expense'])
            ->whereIn('record_public_id', array_map(static fn (ClientExpense $expense): string => $expense->public_id, $expenses))
            ->pluck('public_id')->all();
    }

    public function test_a_receipt_on_an_invoice_the_client_cannot_see_is_not_served(): void
    {
        $client = $this->client();
        foreach (['draft' => ['status' => 'draft'], 'void' => ['status' => 'void'], 'hidden' => ['is_visible_to_client' => false]] as $state => $attributes) {
            [$invoice, $expense, $shared] = $this->billed(invoice: $attributes);

            $this->open($client, $expense, $shared)->assertNotFound();
            $this->assertSame([], $this->readableReceiptIds($client, [$expense]), $state);
            $this->assertNotSame(200, $this->clientPdf($client, $invoice)->getStatusCode(), $state);
        }
    }

    public function test_a_client_of_another_company_in_the_same_workspace_reads_nothing(): void
    {
        [$invoice, $expense, $shared] = $this->billed();
        $other = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic Sibling Client', 'slug' => 'synthetic-sibling-client']);
        $stranger = $this->client($other);

        // Through the right company's route, through their own, and by a document.
        $this->open($stranger, $expense, $shared)->assertNotFound();
        $this->open($stranger, $expense, $shared, $other)->assertNotFound();
        $this->actingAs($stranger)->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}")->assertNotFound();
        $this->assertNotSame(200, $this->clientPdf($stranger, $invoice)->getStatusCode());
        $this->assertSame([], $this->readableReceiptIds($stranger, [$expense]));
    }

    public function test_an_expense_billed_to_another_company_is_not_served_through_a_company_the_client_belongs_to(): void
    {
        $other = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic Sibling Client', 'slug' => 'synthetic-sibling-client']);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $other->id, 'invoice_number' => 'INV-SYN-SIBLING', 'status' => 'issued',
            'currency' => 'USD', 'is_visible_to_client' => true, 'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'balance_amount' => 0,
        ]);
        [$expense] = $this->goldenExpense($invoice, 'Synthetic sibling expense', 500, '2026-08-01', ['client_company_id' => $other->id]);
        $file = $this->goldenReceipt($expense, 'sibling.png', 'image/png', $this->pngBytes());
        $member = $this->client();

        $this->open($member, $expense, $file)->assertNotFound();
        $this->assertSame([], $this->readableReceiptIds($member, [$expense]));
        $this->open($this->client($other), $expense, $file, $other)->assertOk();
    }

    public function test_a_client_of_another_workspace_reads_nothing_and_a_forged_row_is_not_followed(): void
    {
        [, $expense, $shared] = $this->billed();
        $other = Workspace::query()->create(['name' => 'Synthetic other workspace', 'slug' => 'synthetic-other']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic Other Client', 'slug' => 'synthetic-other-client']);
        $outsider = $this->client($otherCompany);

        $this->open($outsider, $expense, $shared, $otherCompany)->assertNotFound();
        $this->open($outsider, $expense, $shared)->assertNotFound();

        // A file row moved to another workspace is not served from this one.
        $forged = $this->goldenReceipt($expense, 'forged.png', 'image/png', $this->pngBytes());
        DB::table('client_attachments')->where('id', $forged->id)->update(['workspace_id' => $other->id]);
        $this->open($this->client(), $expense, $forged)->assertNotFound();
    }

    public function test_a_receipt_that_is_not_available_is_not_served(): void
    {
        [$invoice, $expense] = $this->billed();
        $client = $this->client();
        foreach ([ClientAttachment::STATE_STAGED, ClientAttachment::STATE_DELETING, ClientAttachment::STATE_DELETED, ClientAttachment::STATE_CORRUPT] as $state) {
            $file = $this->goldenReceipt($expense, $state.'.png', 'image/png', $this->pngBytes(), ['lifecycle_state' => $state]);

            $this->open($client, $expense, $file)->assertNotFound();
            $this->assertStringNotContainsString($file->public_id, (string) $this->portalJson($client, $invoice)->getContent());
        }
    }

    public function test_a_user_with_no_membership_and_a_guest_read_nothing(): void
    {
        [$invoice, $expense, $shared] = $this->billed();
        $nobody = User::factory()->create();

        $this->open($nobody, $expense, $shared)->assertNotFound();
        $this->assertNotSame(200, $this->clientPdf($nobody, $invoice)->getStatusCode());
        auth()->logout();
        $this->get(route('portal.files.receipt.download', [
            'clientCompany' => $this->company->public_id, 'recordPublicId' => $expense->public_id, 'attachment' => $shared->public_id,
        ]))->assertRedirect();
    }

    public function test_a_project_scoped_contact_sees_receipts_only_for_a_project_they_hold(): void
    {
        $granted = $this->project;
        $ungranted = $this->goldenProject('Synthetic Ungranted Project');
        // One invoice, lines on the granted project, and three expenses: granted, ungranted, no project.
        $invoice = $this->goldenInvoice();
        $this->goldenLine($invoice, ['client_project_id' => $granted->id, 'sort_order' => 1]);
        [$onGranted] = $this->goldenExpense($invoice, 'Synthetic granted expense', 1000, '2026-08-01', ['client_project_id' => $granted->id], sort: 10);
        [$onUngranted] = $this->goldenExpense($invoice, 'Synthetic ungranted expense', 2000, '2026-08-02', ['client_project_id' => $ungranted->id], sort: 11);
        [$onNone] = $this->goldenExpense($invoice, 'Synthetic unattributed expense', 3000, '2026-08-03', sort: 12);
        $files = [];
        foreach ([$onGranted, $onUngranted, $onNone] as $index => $expense) {
            $files[$index] = $this->goldenReceipt($expense, "r{$index}.png", 'image/png', $this->pngBytes());
        }
        $scoped = $this->scopedClient([$granted]);

        $this->open($scoped, $onGranted, $files[0])->assertOk();
        $this->open($scoped, $onUngranted, $files[1])->assertNotFound();
        $this->open($scoped, $onNone, $files[2])->assertNotFound();
        $this->portalJson($scoped, $invoice)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('document.receipts.receipt_count', 1)
            ->where('document.receipts.entries.0.files.0.filename', 'r0.png'));
        $this->assertSame(1, $this->images($this->clientPdf($scoped, $invoice)->assertOk()));

        // A company-wide contact of the same company reads all three.
        $everything = $this->client();
        foreach ([[$onGranted, $files[0]], [$onUngranted, $files[1]], [$onNone, $files[2]]] as [$expense, $file]) {
            $this->open($everything, $expense, $file)->assertOk();
        }
    }

    public function test_a_project_scoped_contact_who_cannot_see_the_invoice_reads_no_receipt(): void
    {
        $ungranted = $this->goldenProject('Synthetic Ungranted Project');
        [, $expense, $shared] = $this->billed($ungranted);

        $this->open($this->scopedClient([$this->project]), $expense, $shared)->assertNotFound();
        $this->open($this->scopedClient([]), $expense, $shared)->assertNotFound();
    }

    public function test_a_workspace_manager_previewing_the_portal_is_served_the_same_shared_receipts_only(): void
    {
        [, $expense, $shared, $private] = $this->billed();

        $this->open($this->member, $expense, $shared)->assertOk();
        $this->open($this->member, $expense, $private)->assertNotFound();
    }

    public function test_the_default_and_emailed_copies_carry_no_appendix_b_for_anyone(): void
    {
        [$invoice] = $this->billed();
        $service = app(InvoiceDocumentService::class);

        // Emailed, stored and reminder copies ask for no receipts and name no viewer.
        $this->assertSame([], $service->document($invoice, InvoiceLineDetail::CLIENT)->receipts);
        $this->assertSame([], $service->document($invoice, InvoiceLineDetail::CLIENT, viewer: $this->client())->receipts);
        $this->assertSame([], $service->document($invoice, InvoiceLineDetail::CLIENT, receipts: ReceiptDetail::Embedded)->receipts, 'A client copy built for no one reads nothing.');
        $this->assertSame(0, $this->images($this->fakeResponse($service->pdf($invoice, InvoiceLineDetail::CLIENT))));
    }

    private function fakeResponse(string $pdf): TestResponse
    {
        return new TestResponse(response($pdf));
    }

    public function test_the_receipt_queries_do_not_grow_with_the_number_of_receipts(): void
    {
        $few = $this->goldenInvoice();
        [$one] = $this->goldenExpense($few, 'Synthetic one', 100, '2026-08-01');
        $this->goldenReceipt($one, 'one.pdf', 'application/pdf', '%PDF-1.4 one');
        $many = $this->goldenInvoice();
        foreach (range(1, 12) as $number) {
            [$expense] = $this->goldenExpense($many, 'Synthetic expense '.$number, 100 * $number, '2026-08-'.sprintf('%02d', $number), sort: 10 + $number);
            $this->goldenReceipt($expense, "r{$number}.png", 'image/png', $this->pngBytes());
            $this->goldenReceipt($expense, "r{$number}.pdf", 'application/pdf', '%PDF-1.4 r');
        }
        $client = $this->client();

        $queries = function (ClientInvoice $invoice, ReceiptDetail $detail) use ($client): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(InvoiceDocumentBuilder::class)->build($invoice, InvoiceLineDetail::CLIENT, receipts: $detail, viewer: $client);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $base = $queries($few, ReceiptDetail::None);
        // The expenses, the viewer's role (portal or preview), then every receipt in one query.
        $this->assertSame($base + 3, $queries($few, ReceiptDetail::Listed));
        $this->assertSame($base + 3, $queries($many, ReceiptDetail::Listed), 'Twelve expenses and twenty-four files cost the same.');
        $this->assertSame($base + 3, $queries($many, ReceiptDetail::Embedded), 'Reading the files is disk work, not queries.');
    }
}
