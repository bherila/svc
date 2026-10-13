<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\SquareInvoiceSnapshot;
use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareInvoicePage;
use App\Services\Square\Mirror\SquareMirrorSync;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\ServesSyntheticSquareAccount;
use Tests\TestCase;

final class SquareHostedInvoicePageTest extends TestCase
{
    use FakesSquare;
    use RefreshDatabase;
    use ServesSyntheticSquareAccount;

    public function test_operator_and_client_receive_only_the_linked_mirrored_public_url(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->fixture();
        $url = 'https://squareupsandbox.com/pay-invoice/inv:0-fixture1';
        $operatorUrl = route('clients.invoice', [$workspace, $company, $invoice]);
        $this->actingAs($owner)->get($operatorUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('square.hosted_page.url', $url)
            ->where('square.hosted_page.square_status', 'UNPAID'));
        $client = $this->client($company);
        $portalUrl = route('portal.invoice', [$company, $invoice]);
        $this->actingAs($client)->get($portalUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('square_page_url', $url)
            ->missing('square'));
        Http::assertNotSent(static fn ($request): bool => $request->method() !== 'GET');

        $snapshot = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv:0-fixture1')->firstOrFail();
        $snapshot->forceFill(['public_url' => 'https://untrusted.example.test/inv_fixture_1'])->save();
        $this->actingAs($client)->get($portalUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->where('square_page_url', null));
        $snapshot->forceFill(['public_url' => null])->save();
        $this->assertNull(app(SquareInvoicePage::class)->forInvoice($workspace, $invoice), 'Never build a pay URL from a Square identifier.');
    }

    public function test_hosted_status_freshness_reports_the_latest_unchanged_observation(): void
    {
        Date::setTestNow('2030-01-01 12:00:00');
        [$workspace, $owner, $company, $invoice] = $this->fixture();
        Date::setTestNow('2030-01-02 12:00:00');
        $sync = app(SquareMirrorSync::class);
        $this->assertSame(SquareSyncRun::COMPLETED, $sync->perform($workspace, $sync->request($workspace, SquareSyncRun::TRIGGER_SCHEDULE))->status);
        $snapshot = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv:0-fixture1')->firstOrFail();
        $this->assertSame('2030-01-01 12:00:00', $snapshot->synced_at->format('Y-m-d H:i:s'));
        $this->actingAs($owner)->get(route('clients.invoice', [$workspace, $company, $invoice]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('square.hosted_page.synced_at', '2030-01-02T12:00:00Z'));
    }

    public function test_a_client_cannot_see_a_linked_invoice_of_another_company_or_workspace(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->fixture();
        $client = $this->client($company);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other company', 'slug' => 'synthetic-other-company']);
        $otherInvoice = $this->invoice($otherCompany, 'SYNTHETIC-OTHER');
        app(SquareLinkService::class)->link($workspace, $otherInvoice, $owner, AgentApiVersion::for($otherInvoice), new SquareInvoiceReference('inv_fixture_other', '000002-R-0001', null, SquareMatchMethod::Manual), false);
        $otherWorkspace = Workspace::query()->create(['name' => 'Synthetic other workspace', 'slug' => 'synthetic-other-hosted']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Synthetic foreign company', 'slug' => 'synthetic-foreign-company']);
        $foreignInvoice = $this->invoice($foreignCompany, 'SYNTHETIC-FOREIGN');
        $otherWorkspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        app(SquareLinkService::class)->link($otherWorkspace, $foreignInvoice, $owner, AgentApiVersion::for($foreignInvoice), new SquareInvoiceReference('inv_fixture_foreign', '000003-R-0001', null, SquareMatchMethod::Manual), false);

        $this->actingAs($client)->get(route('portal.invoice', [$company, $invoice]))->assertOk();
        foreach ([$otherInvoice, $foreignInvoice] as $hidden) {
            $response = $this->actingAs($client)->get(route('portal.invoice', [$company, $hidden]))->assertNotFound();
            $this->assertStringNotContainsString($hidden->invoice_number, (string) $response->getContent());
        }
        $this->actingAs($client)->get(route('portal.invoice', [$otherCompany, $otherInvoice]))->assertNotFound();
        $this->assertNull(app(SquareInvoicePage::class)->forInvoice($otherWorkspace, $invoice));
    }

    public function test_no_configuration_no_link_and_void_invoices_offer_no_hosted_page(): void
    {
        [$workspace, , $company, $invoice] = $this->fixture();
        $client = $this->client($company);
        $invoice->forceFill(['status' => 'void'])->save();
        $this->actingAs($client)->get(route('portal.invoice', [$company, $invoice]))->assertNotFound();
        $invoice->forceFill(['status' => 'issued'])->save();
        config(['services.square.access_token' => null]);
        $this->actingAs($client)->get(route('portal.invoice', [$company, $invoice]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('square_page_url', null));
        $this->connectSquare($workspace);
        $unlinked = $this->invoice($company, 'SYNTHETIC-UNLINKED');
        $this->assertNull(app(SquareInvoicePage::class)->forInvoice($workspace, $unlinked));
    }

    private function fixture(): array
    {
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic hosted pages', 'slug' => 'synthetic-hosted-pages']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic hosted client', 'slug' => 'synthetic-hosted-client']);
        $invoice = $this->invoice($company);
        $this->connectSquare($workspace);
        $this->serveSquareAccount(invoices: [self::squareInvoice()]);
        $sync = app(SquareMirrorSync::class);
        $sync->perform($workspace, $sync->request($workspace, SquareSyncRun::TRIGGER_SCHEDULE));
        app(SquareLinkService::class)->link($workspace, $invoice, $owner, AgentApiVersion::for($invoice), new SquareInvoiceReference('inv:0-fixture1', '000001-R-0001', null, SquareMatchMethod::Manual), false);

        return [$workspace, $owner, $company, $invoice];
    }

    private function client(ClientCompany $company): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);

        return $user;
    }

    private function invoice(ClientCompany $company, string $number = 'SYNTHETIC-1'): ClientInvoice
    {
        return ClientInvoice::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'invoice_number' => $number, 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 12_345, 'subtotal_amount' => 12_345, 'tax_amount' => 0, 'paid_amount' => 0, 'balance_amount' => 12_345, 'is_visible_to_client' => true]);
    }
}
