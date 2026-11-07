<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\Concerns\BuildsReceiptInvoices;
use Tests\TestCase;

/**
 * Appendix B through the routes that serve it: the operator screen, the portal
 * screen and the PDF. Who may open a receipt is decided here, not in the
 * document: a manager gets the receipts and finished links, a scoped member
 * who may read the invoice gets none, and a client gets none.
 */
class InvoiceReceiptsScreensTest extends TestCase
{
    use BuildsGoldenInvoices;
    use BuildsReceiptInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
        $this->fakeReceiptDisk();
    }

    private function invoiceWithReceipts(): ClientInvoice
    {
        $invoice = $this->goldenInvoice();
        // Attributed to the project, so a member holding it may read the invoice.
        $this->goldenLine($invoice, ['client_project_id' => $this->project->id, 'sort_order' => 1]);
        [$staging] = $this->goldenExpense($invoice, 'Synthetic staging database', 41200, '2026-08-12', sort: 10);
        [$api] = $this->goldenExpense($invoice, 'Synthetic lab-results API usage', 9900, '2026-08-20', sort: 11);
        $this->goldenReceipt($staging, 'staging-august.png', 'image/png', $this->pngBytes());
        $this->goldenReceipt($staging, 'staging-august.pdf', 'application/pdf', '%PDF-1.4 synthetic receipt');
        $this->goldenReceipt($api, 'api-usage.jpg', 'image/jpeg', $this->jpegBytes());

        return $invoice;
    }

    private function scopedMember(): User
    {
        $user = User::factory()->create(['email' => 'scoped-member@example.test']);
        $this->workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id,
            'user_id' => $user->id, 'role' => ProjectRole::Contributor,
        ]);

        return $user;
    }

    private function screen(User $user, ClientInvoice $invoice): TestResponse
    {
        return $this->actingAs($user)->get(route('clients.invoice', [$this->workspace, $this->company, $invoice]));
    }

    private function pdfUrl(ClientInvoice $invoice): string
    {
        return "/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/pdf";
    }

    public function test_a_manager_sees_the_receipts_with_finished_links_on_the_operator_screen(): void
    {
        $invoice = $this->invoiceWithReceipts();

        $this->screen($this->member, $invoice)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('clients/invoice')
            ->where('document.receipts.receipt_count', 3)
            ->where('document.receipts.entries.0.ref', 'B1')
            ->where('document.receipts.entries.0.files.0.filename', 'staging-august.png')
            ->where('document.receipts.entries.0.files.0.href', fn ($href): bool => is_string($href) && str_starts_with($href, "/workspaces/{$this->workspace->public_id}/attachments/"))
            ->where('document.receipts.entries.0.files.1.note', 'Not drawn in the PDF; open the file from the invoice online.'));
    }

    public function test_a_scoped_member_who_may_read_the_invoice_is_given_no_receipts(): void
    {
        $invoice = $this->invoiceWithReceipts();

        $response = $this->screen($this->scopedMember(), $invoice)->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->where('document.receipts', null));
        foreach (['staging-august', 'api-usage', '/attachments/'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->getContent());
        }
    }

    public function test_a_client_on_the_portal_is_given_the_shared_receipts_with_portal_links(): void
    {
        $invoice = $this->invoiceWithReceipts();
        $client = User::factory()->create(['email' => 'portal-client@example.test']);
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id, 'user_id' => $client->id, 'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        $response = $this->actingAs($client)->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}")->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('portal/invoice')
            ->where('document.receipts.receipt_count', 3)
            ->where('document.receipts.entries.0.files.0.href', fn ($href): bool => is_string($href)
                && str_starts_with($href, "/portal/{$this->company->public_id}/expenses/")
                && ! str_contains($href, '/workspaces/')));
        $this->assertStringNotContainsString('/attachments/', (string) $response->getContent());
    }

    public function test_the_pdf_draws_receipt_images_for_a_manager_and_a_client_but_not_for_a_scoped_member(): void
    {
        $invoice = $this->invoiceWithReceipts();
        $client = User::factory()->create(['email' => 'portal-client@example.test']);
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id, 'user_id' => $client->id, 'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        $manager = $this->actingAs($this->member)->get($this->pdfUrl($invoice))->assertOk();
        $member = $this->actingAs($this->scopedMember())->get($this->pdfUrl($invoice))->assertOk();
        $portal = $this->actingAs($client)->get($this->pdfUrl($invoice))->assertOk();

        $this->assertSame(2, substr_count((string) $manager->getContent(), '/Subtype /Image'));
        $this->assertSame(0, substr_count((string) $member->getContent(), '/Subtype /Image'));
        // The client's copy draws the receipts shared with them.
        $this->assertSame(2, substr_count((string) $portal->getContent(), '/Subtype /Image'));
        $this->assertSame('application/pdf', $manager->headers->get('Content-Type'));
    }
}
