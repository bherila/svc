<?php

namespace Tests\Feature\Portal;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

/**
 * One invoice, as the client it was sent to.
 *
 * A client-reachable route, so the refusals are the feature rather than the
 * edge cases. The portal's list already decides what a client may see - this
 * screen resolves through the same query rather than restating it, because a
 * detail that admitted one invoice the list would not is exactly the bug: the
 * client never sees the row and reaches it by id.
 *
 * Fixtures are synthetic throughout.
 */
final class PortalInvoiceDetailTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create([
            'name' => 'Synthetic Portal WS',
            'slug' => 'synthetic-portal-ws',
        ]);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Synthetic Portal Client',
            'slug' => 'synthetic-portal-client',
        ]);
    }

    public function test_a_client_sees_the_lines_of_an_invoice_sent_to_them(): void
    {
        $invoice = $this->invoice('SENT-1', ['is_visible_to_client' => true, 'status' => 'issued']);
        $this->line($invoice, 'Synthetic consulting work', '2.5000');

        $this->actingAs($this->portalUser())
            ->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/invoice')
                ->where('invoice.invoice_number', 'SENT-1')
                ->has('lines', 1)
                ->where('lines.0.description', 'Synthetic consulting work'));
    }

    /**
     * A draft is working arithmetic nobody has committed to.
     *
     * The list excludes it, so the detail must too - otherwise the client
     * never sees the row and reaches it by id, and argues about a number that
     * was never sent.
     */
    public function test_a_draft_invoice_is_not_reachable_by_id(): void
    {
        $draft = $this->invoice('DRAFT-1', ['is_visible_to_client' => true, 'status' => 'draft']);
        $this->line($draft, 'Synthetic draft line', '9.0000');

        $response = $this->actingAs($this->portalUser())
            ->get("/portal/{$this->company->public_id}/invoices/{$draft->public_id}");

        $response->assertNotFound();
        $this->assertStringNotContainsString('Synthetic draft line', (string) $response->getContent());
    }

    /**
     * An operator hiding an invoice is a disclosure decision, not a display one.
     */
    public function test_an_invoice_hidden_from_the_client_is_not_reachable_by_id(): void
    {
        $hidden = $this->invoice('HIDDEN-1', ['is_visible_to_client' => false, 'status' => 'issued']);

        $this->actingAs($this->portalUser())
            ->get("/portal/{$this->company->public_id}/invoices/{$hidden->public_id}")
            ->assertNotFound();
    }

    /**
     * Another client's invoice, through a portal this user legitimately holds.
     *
     * Invoices bind by a public id unique across every workspace, so passing
     * the portal gate for your own company is not passing a check on the
     * invoice named in the URL.
     */
    public function test_another_companys_invoice_is_not_reachable(): void
    {
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign Portal WS',
            'slug' => 'foreign-portal-ws',
        ]);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign Portal Client Name',
            'slug' => 'foreign-portal-client',
        ]);

        $foreign = ClientInvoice::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'invoice_number' => 'FOREIGN-PORTAL-1',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
            'balance_amount' => 5000,
        ]);
        $foreign->forceFill(['is_visible_to_client' => true])->save();

        $this->actingAs($this->portalUser())
            ->get("/portal/{$this->company->public_id}/invoices/{$foreign->public_id}")
            ->assertNotFound();
    }

    /** Someone with no portal membership at all reaches nothing. */
    public function test_a_stranger_cannot_open_the_invoice(): void
    {
        $invoice = $this->invoice('SENT-2', ['is_visible_to_client' => true, 'status' => 'issued']);

        $this->actingAs(User::factory()->create())
            ->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}")
            ->assertForbidden();
    }

    /**
     * Nothing on a client-reachable route mutates.
     *
     * The screen offers no action, but "there is no button" is not the
     * property - the route refusing a write is.
     */
    public function test_the_invoice_route_refuses_a_write(): void
    {
        $invoice = $this->invoice('SENT-3', ['is_visible_to_client' => true, 'status' => 'issued']);
        $path = "/portal/{$this->company->public_id}/invoices/{$invoice->public_id}";

        foreach (['post', 'patch', 'put', 'delete'] as $verb) {
            $this->actingAs($this->portalUser())
                ->{$verb}($path)
                ->assertStatus(405);
        }
    }

    private function portalUser(): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id,
            'user_id' => $user->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(string $number, array $overrides): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => $number,
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'balance_amount' => 10000,
        ]);

        $invoice->forceFill($overrides)->save();

        return $invoice;
    }

    private function line(ClientInvoice $invoice, string $description, string $quantity): ClientInvoiceLine
    {
        return ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'time',
            'description' => $description,
            'quantity' => $quantity,
            'unit_amount' => 4000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'sort_order' => 0,
        ]);
    }
}
