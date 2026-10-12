<?php

namespace Tests\Feature\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The operator screens show SVC's Square state (#438): the invoice's link with
 * its controls, the client's Square customer, the agreement's marker. The
 * client portal shows none of it, and no screen carries a Square address.
 */
final class SquareOperatorScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invoice_screen_shows_the_link_and_withholds_collection_actions(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->fixture();
        $base = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices/{$invoice->public_id}";
        $linkHref = route('agent-api.v1.invoices.square-link.store', [$workspace, $invoice->public_id], absolute: false);

        $this->actingAs($owner)->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('clients/invoice')
            ->where('square.link', null)
            ->where('square.square_collects', false)
            ->where('square.link_href', $linkHref)
            ->where('square.unlink_href', null)
            ->where('actions.send', "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send")
            ->where('actions.release_automatic', "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/automatic-delivery/release"));

        app(SquareLinkService::class)->link($workspace, $invoice, $owner, AgentApiVersion::for($invoice), new SquareInvoiceReference('inv:0-fixture1', '000001-R-0003', null, SquareMatchMethod::Suggested, 90, ['exact amount']), false);

        $response = $this->actingAs($owner)->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('square.link.square_invoice_number', '000001-R-0003')
            ->where('square.link.series_key', '000001')
            ->where('square.link.match_method', 'suggested')
            ->where('square.link.score', 90)
            ->where('square.link.linked_by', $owner->name)
            ->where('square.square_collects', true)
            ->where('square.unlink_href', $linkHref)
            ->where('version', AgentApiVersion::for($invoice->refresh()))
            ->where('square.invoice_version', AgentApiVersion::for($invoice))
            ->where('actions.send', null)
            ->where('actions.release_automatic', null)
            ->where('actions.payment', "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments"));
        $this->assertStringNotContainsStringIgnoringCase('squareup', (string) $response->getContent());
    }

    public function test_a_viewer_who_cannot_manage_sees_no_square_state(): void
    {
        [$workspace, , $company, $invoice] = $this->fixture();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Square Project']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $member->id, 'role' => 'contributor']);
        ClientInvoiceLine::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'client_project_id' => $project->id, 'type' => 'service', 'description' => 'Synthetic work', 'quantity' => '1', 'unit_amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000, 'sort_order' => 0]);

        $this->actingAs($member)->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices/{$invoice->public_id}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('square', null));
    }

    public function test_the_settings_screen_shows_the_clients_square_customer(): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        $settings = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}/settings";

        $this->actingAs($owner)->get($settings)->assertOk()->assertInertia(fn (Assert $page) => $page->where('company.square_customer', null));

        app(SquareLinkService::class)->setCustomer($workspace, $company, $owner, AgentApiVersion::for($company), 'CUSTFIXTUREA');

        $this->actingAs($owner)->get($settings)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('company.square_customer.square_customer_id', 'CUSTFIXTUREA')
            ->where('company.square_customer.confirmed_by', $owner->name));
    }

    public function test_the_agreement_screen_shows_the_marker_to_operators_only(): void
    {
        [$workspace, $owner, $company, , $agreement] = $this->fixture();
        app(SquareLinkService::class)->markAgreement($workspace, $agreement, $owner, AgentApiVersion::for($agreement), '000001');

        $this->actingAs($owner)->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('agreement.square_series_key', '000001'));

        $client = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $client->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $this->actingAs($client)->get("/portal/{$company->public_id}/agreements/{$agreement->public_id}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('audience', 'client')
            ->missing('agreement.square_series_key'));
    }

    /** @return array{Workspace, User, ClientCompany, ClientInvoice, ClientAgreement} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Square Screens', 'slug' => 'synthetic-square-screens-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Square Client', 'slug' => 'synthetic-square-client', 'billing_email' => 'billing@synthetic.example.test']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Monthly', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000, 'is_visible_to_client' => true]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYN-1', 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-31', 'document_revision' => 1, 'currency' => 'USD', 'subtotal_amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000, 'paid_amount' => 0, 'balance_amount' => 5000, 'automatic_delivery_status' => 'held', 'automatic_delivery_due_at' => now()->addDay()]);

        return [$workspace, $owner, $company, $invoice, $agreement];
    }
}
