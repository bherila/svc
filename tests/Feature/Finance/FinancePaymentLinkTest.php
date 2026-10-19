<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\FinancePaymentLinkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * A payment's permanent deep link (`/finance-links/payments/{uuid}`): it opens
 * the payment's invoice screen for a reader who may see it, and every other
 * reader gets exactly what an unknown UUID gets.
 */
final class FinancePaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_is_redirected_to_the_payment_on_its_invoice_screen(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['owner'])
            ->get($this->link($fixture['payment']->public_id))
            ->assertRedirect($this->destination($fixture));
    }

    public function test_the_destination_it_redirects_to_opens_for_that_reader(): void
    {
        $fixture = $this->fixture();

        $location = $this->actingAs($fixture['owner'])
            ->get($this->link($fixture['payment']->public_id))
            ->headers->get('Location');

        $this->assertIsString($location);
        $this->actingAs($fixture['owner'])->get(explode('#', $location)[0])->assertOk();
    }

    public function test_an_uppercase_uuid_resolves_to_the_same_payment(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['owner'])
            ->get($this->link(strtoupper($fixture['payment']->public_id)))
            ->assertRedirect($this->destination($fixture));
    }

    public function test_an_admin_is_redirected(): void
    {
        $fixture = $this->fixture();
        $admin = User::factory()->create();
        $fixture['workspace']->users()->attach($admin, ['role' => 'admin']);

        $this->actingAs($admin)
            ->get($this->link($fixture['payment']->public_id))
            ->assertRedirect($this->destination($fixture));
    }

    public function test_a_member_who_reaches_the_invoice_through_its_project_is_redirected(): void
    {
        $fixture = $this->fixture();
        $member = $this->projectMember($fixture['workspace'], $fixture['project']);

        $this->actingAs($member)
            ->get($this->link($fixture['payment']->public_id))
            ->assertRedirect($this->destination($fixture));
    }

    public function test_an_unknown_uuid_is_not_found(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['owner'])
            ->get($this->link((string) Str::uuid()))
            ->assertNotFound();
    }

    public function test_a_malformed_id_is_not_found(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['owner'])->get('/finance-links/payments/not-a-uuid')->assertNotFound();
        // An integer key is a locator, never an address another system may keep.
        $this->actingAs($fixture['owner'])->get('/finance-links/payments/'.$fixture['payment']->id)->assertNotFound();
    }

    public function test_a_payment_in_a_workspace_the_reader_is_not_a_member_of_is_indistinguishable_from_an_unknown_one(): void
    {
        $fixture = $this->fixture();
        $outsider = $this->fixture('Outsider workspace')['owner'];

        $denied = $this->actingAs($outsider)->get($this->link($fixture['payment']->public_id));
        $unknown = $this->actingAs($outsider)->get($this->link((string) Str::uuid()));

        $this->assertIndistinguishable($unknown, $denied);
    }

    public function test_a_member_who_reaches_no_project_of_the_client_is_indistinguishable_from_an_unknown_payment(): void
    {
        $fixture = $this->fixture();
        $member = User::factory()->create();
        $fixture['workspace']->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $denied = $this->actingAs($member)->get($this->link($fixture['payment']->public_id));
        $unknown = $this->actingAs($member)->get($this->link((string) Str::uuid()));

        $this->assertIndistinguishable($unknown, $denied);
    }

    public function test_a_member_who_reaches_the_client_but_not_every_project_on_the_invoice_is_refused(): void
    {
        $fixture = $this->fixture();
        $member = $this->projectMember($fixture['workspace'], $fixture['project']);
        $hidden = ClientProject::query()->create([
            'workspace_id' => $fixture['workspace']->id,
            'client_company_id' => $fixture['company']->id,
            'name' => 'Hidden project',
            'status' => 'active',
        ]);
        $invoice = $this->invoiceWithPayment($fixture['workspace'], $fixture['company'], [$fixture['project'], $hidden]);
        $payment = $invoice->payments()->firstOrFail();

        $denied = $this->actingAs($member)->get($this->link($payment->public_id));
        $unknown = $this->actingAs($member)->get($this->link((string) Str::uuid()));

        $this->assertIndistinguishable($unknown, $denied);
    }

    public function test_a_client_portal_reader_is_refused(): void
    {
        $fixture = $this->fixture();
        $portalReader = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'client_company_id' => $fixture['company']->id,
            'user_id' => $portalReader->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        $denied = $this->actingAs($portalReader)->get($this->link($fixture['payment']->public_id));
        $unknown = $this->actingAs($portalReader)->get($this->link((string) Str::uuid()));

        $this->assertIndistinguishable($unknown, $denied);
    }

    public function test_a_guest_is_sent_to_sign_in_whether_or_not_the_payment_exists(): void
    {
        $fixture = $this->fixture();

        $this->get($this->link($fixture['payment']->public_id))->assertRedirect(route('login'));
        $this->get($this->link((string) Str::uuid()))->assertRedirect(route('login'));
    }

    public function test_the_resolver_never_loads_an_invoice_outside_the_readers_workspaces(): void
    {
        $fixture = $this->fixture();
        $outsider = $this->fixture('Outsider workspace')['owner'];
        $resolver = app(FinancePaymentLinkResolver::class);

        $tables = [];
        DB::listen(function ($query) use (&$tables): void {
            if (preg_match('/from [`"]?(\w+)[`"]?/i', $query->sql, $match) === 1) {
                $tables[] = $match[1];
            }
        });

        $this->assertNull($resolver->destination($outsider, $fixture['payment']->public_id));
        $this->assertSame(['client_invoice_payments'], array_values(array_unique($tables)));
    }

    /**
     * @param  TestResponse<Response>  $unknown
     * @param  TestResponse<Response>  $denied
     */
    private function assertIndistinguishable(TestResponse $unknown, TestResponse $denied): void
    {
        $unknown->assertNotFound();
        $denied->assertNotFound();
        $this->assertSame($unknown->getContent(), $denied->getContent());
        $this->assertNull($denied->headers->get('Location'));
    }

    private function link(string $paymentPublicId): string
    {
        return '/finance-links/payments/'.$paymentPublicId;
    }

    /**
     * @param  array{workspace: Workspace, company: ClientCompany, invoice: ClientInvoice, payment: ClientInvoicePayment}  $fixture
     */
    private function destination(array $fixture): string
    {
        return "/workspaces/{$fixture['workspace']->public_id}/clients/{$fixture['company']->public_id}"
            ."/invoices/{$fixture['invoice']->public_id}#payment-{$fixture['payment']->public_id}";
    }

    private function projectMember(Workspace $workspace, ClientProject $project): User
    {
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        return $member;
    }

    /**
     * @return array{owner: User, workspace: Workspace, company: ClientCompany, project: ClientProject, invoice: ClientInvoice, payment: ClientInvoicePayment}
     */
    private function fixture(string $name = 'Deep link workspace'): array
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        $owner = User::factory()->create();
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name.' client',
            'slug' => Str::slug($name).'-client-'.Str::lower(Str::random(5)),
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => $name.' project',
            'status' => 'active',
        ]);
        $invoice = $this->invoiceWithPayment($workspace, $company, [$project]);

        return [
            'owner' => $owner,
            'workspace' => $workspace,
            'company' => $company,
            'project' => $project,
            'invoice' => $invoice,
            'payment' => $invoice->payments()->firstOrFail(),
        ];
    }

    /**
     * An issued invoice with one line per project and one successful payment.
     *
     * @param  list<ClientProject>  $projects
     */
    private function invoiceWithPayment(Workspace $workspace, ClientCompany $company, array $projects): ClientInvoice
    {
        $lifecycle = app(InvoiceLifecycleService::class);
        $invoice = $lifecycle->createDraft($workspace, $company, [
            'invoice_number' => 'INV-SYNTH-'.Str::upper(Str::random(10)),
            'currency' => 'USD',
        ], array_map(fn (ClientProject $project): array => [
            'type' => 'service',
            'description' => 'Synthetic deep link service',
            'quantity' => '1',
            'unit_amount' => 5000,
            'tax_amount' => 0,
            'client_project_id' => $project->id,
        ], $projects));
        $lifecycle->issue($invoice, $workspace);
        $lifecycle->applyPayment($invoice, [
            'amount' => 5000,
            'currency' => 'USD',
            'method' => 'wire',
            'status' => 'succeeded',
            'idempotency_key' => 'payment-'.Str::random(16),
        ], $workspace);

        return $invoice->fresh();
    }
}
