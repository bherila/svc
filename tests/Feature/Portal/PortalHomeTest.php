<?php

namespace Tests\Feature\Portal;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

/**
 * The client portal's home (docs/ux-overhaul.md section 6): Balance, This
 * period and the invoice history.
 *
 * A client-reachable page, so the refusals are the feature. Each card is
 * checked against rows it must never show: another company's, another
 * workspace's, a draft, an invoice the operator did not disclose, and
 * workspace-internal fields. Today is 2026-11-06.
 */
final class PortalHomeTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientCompany $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');

        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Portal WS', 'slug' => 'synthetic-portal-home']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic Portal Client', 'slug' => 'synthetic-portal-home-client']);
        $this->otherCompany = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Other Synthetic Client', 'slug' => 'other-synthetic-home-client']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function portalUser(?ClientCompany $company = null, string $scope = ClientCompanyMembership::SCOPE_COMPANY): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => ($company ?? $this->company)->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => $scope]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(string $number, array $overrides = [], ?ClientCompany $company = null, ?Workspace $workspace = null): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => ($workspace ?? $this->workspace)->id,
            'client_company_id' => ($company ?? $this->company)->id,
            'invoice_number' => $number,
            'status' => 'issued',
            'currency' => 'USD',
            'issue_date' => '2026-10-01',
            'due_date' => '2026-11-15',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'balance_amount' => 10000,
            'is_visible_to_client' => true,
        ]);
        $invoice->forceFill($overrides)->save();

        return $invoice;
    }

    /** @param array<string, mixed> $overrides */
    private function agreement(array $overrides = [], ?ClientCompany $company = null): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => ($company ?? $this->company)->id,
            'title' => 'Synthetic portal retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 800000, 'hourly_rate_amount' => 20000,
            'is_visible_to_client' => true, ...$overrides,
        ]);
    }

    private function home(User $user, ?ClientCompany $company = null): TestResponse
    {
        return $this->actingAs($user)->get('/portal/'.($company ?? $this->company)->public_id)->assertOk();
    }

    public function test_the_balance_adds_up_what_is_open_and_names_the_invoice_due_first(): void
    {
        $this->invoice('SYN-LATE', ['balance_amount' => 5000, 'due_date' => '2026-12-01']);
        $this->invoice('SYN-SOON', ['status' => 'partially_paid', 'balance_amount' => 920000, 'due_date' => '2026-11-15']);

        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')
            ->where('balance.amount_due', 925000)
            ->where('balance.currency', 'USD')
            ->where('balance.invoice_count', 2)
            ->where('balance.overdue', false)
            ->where('balance.first.number', 'SYN-SOON')
            ->where('balance.first.days_until_due', 9)
            ->where('pay.invoice_href', fn (string $href): bool => str_starts_with($href, '/portal/'.$this->company->public_id.'/invoices/'))
            ->where('pay.href', null));
    }

    public function test_an_overdue_invoice_flags_the_balance(): void
    {
        $this->invoice('SYN-OVERDUE', ['balance_amount' => 7000, 'due_date' => '2026-11-05']);
        $this->invoice('SYN-TODAY', ['balance_amount' => 3000, 'due_date' => '2026-11-06']);

        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page
            ->where('balance.overdue', true)
            ->where('balance.overdue_amount', 7000)
            ->where('balance.first.number', 'SYN-OVERDUE')
            ->where('balance.first.days_until_due', -1));
    }

    public function test_nothing_owed_says_so_and_offers_no_payment(): void
    {
        $this->invoice('SYN-PAID', ['status' => 'paid', 'paid_amount' => 10000, 'balance_amount' => 0]);

        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page
            ->where('balance.amount_due', 0)
            ->where('balance.invoice_count', 0)
            ->where('balance.first', null)
            ->where('pay.href', null)
            ->where('pay.invoice_href', null)
            ->has('recent_invoices', 1));
    }

    public function test_only_the_clients_own_disclosed_collectible_invoices_count(): void
    {
        [, $foreignCompany] = [null, ClientCompany::query()->create([
            'workspace_id' => Workspace::query()->create(['name' => 'Foreign WS', 'slug' => 'foreign-home-ws'])->id, 'name' => 'Foreign Client', 'slug' => 'foreign-home-client',
        ])];

        $this->invoice('SYN-VISIBLE', ['balance_amount' => 1000]);
        $this->invoice('SECRET-DRAFT', ['status' => 'draft', 'balance_amount' => 20000]);
        $this->invoice('SECRET-HIDDEN', ['is_visible_to_client' => false, 'balance_amount' => 40000]);
        $this->invoice('SECRET-VOID', ['status' => 'void', 'balance_amount' => 80000]);
        $this->invoice('SECRET-DISPUTED', ['status' => 'disputed', 'balance_amount' => 160000]);
        $this->invoice('SECRET-OTHER-COMPANY', ['balance_amount' => 320000], $this->otherCompany);
        $this->invoice('SECRET-OTHER-TENANT', ['balance_amount' => 640000], $foreignCompany, $foreignCompany->workspace);

        $response = $this->home($this->portalUser());

        $response->assertInertia(fn (Assert $page) => $page
            ->where('balance.amount_due', 1000)
            ->where('balance.invoice_count', 1)
            ->has('recent_invoices', 2));
        $this->assertInertiaPayloadOmits($response, ['SECRET-DRAFT', 'SECRET-HIDDEN', 'SECRET-VOID', 'SECRET-OTHER-COMPANY', 'SECRET-OTHER-TENANT', 'Other Synthetic Client', 'Foreign Client'], 'SYN-VISIBLE');
    }

    public function test_another_company_user_cannot_open_the_page(): void
    {
        $this->actingAs($this->portalUser($this->otherCompany))->get('/portal/'.$this->company->public_id)->assertNotFound();
    }

    public function test_the_last_payment_is_the_newest_succeeded_one_on_a_visible_invoice(): void
    {
        $invoice = $this->invoice('SYN-PAID-1', ['status' => 'partially_paid', 'paid_amount' => 880000, 'balance_amount' => 50000]);
        $hidden = $this->invoice('SECRET-HIDDEN-PAID', ['is_visible_to_client' => false]);
        $other = $this->invoice('SECRET-OTHER-PAID', [], $this->otherCompany);
        foreach ([
            [$invoice, 'succeeded', '2026-10-01', 100000, 0],
            [$invoice, 'succeeded', '2026-10-12', 880000, 80000],
            [$invoice, 'failed', '2026-10-20', 999999, 0],
            [$invoice, 'pending', '2026-10-21', 888888, 0],
            [$hidden, 'succeeded', '2026-10-25', 777777, 0],
            [$other, 'succeeded', '2026-10-26', 666666, 0],
        ] as [$target, $status, $day, $amount, $refunded]) {
            ClientInvoicePayment::query()->create([
                'workspace_id' => $this->workspace->id, 'client_invoice_id' => $target->id, 'status' => $status, 'amount' => $amount,
                'refunded_amount' => $refunded, 'currency' => 'USD', 'received_on' => $day, 'method' => 'bank_transfer',
            ]);
        }

        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page
            ->where('last_payment', ['amount' => 800000, 'currency' => 'USD', 'received_on' => '2026-10-12']));
    }

    public function test_no_payment_yet_is_null(): void
    {
        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page->where('last_payment', null)->where('this_period', null));
    }

    public function test_this_period_shows_hours_used_the_next_invoice_and_its_parts(): void
    {
        $agreement = $this->agreement();
        ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $this->workspace->id, 'client_agreement_id' => $agreement->id, 'description' => 'Synthetic web hosting',
            'cadence' => 'monthly', 'anchor_day' => 20, 'amount' => 15000, 'quantity' => '1.000', 'currency' => 'USD', 'is_active' => true, 'sort_order' => 1,
        ]);
        $project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic Project', 'status' => 'active', 'is_visible_to_client' => true]);
        $worker = User::factory()->create();
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $project->id, 'user_id' => $worker->id,
            'worked_on' => '2026-11-02', 'minutes' => 150, 'description' => 'Synthetic internal note', 'status' => 'approved',
        ]);

        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page
            ->where('this_period.agreement.title', 'Synthetic portal retainer')
            ->where('this_period.period_start', '2026-11-01')
            ->where('this_period.period_end', '2026-11-30')
            ->where('this_period.use.minutes_used', 150)
            ->where('this_period.use.minutes_included', 600)
            ->where('this_period.use.overage_minutes', 0)
            ->where('this_period.next_invoice.period_start', '2026-11-01')
            ->where('this_period.next_invoice.total', 815000)
            ->where('this_period.next_invoice.excludes', 'tax and expenses')
            ->where('this_period.next_invoice.parts.0.kind', 'retainer')
            ->where('this_period.next_invoice.parts.1.description', fn (string $description): bool => str_contains($description, 'Synthetic web hosting'))
            ->where('this_period.next_invoice.parts.1.amount', 15000)
            ->where('this_period.still_deferred', null));
    }

    public function test_hours_past_the_retainer_are_priced_into_the_estimate(): void
    {
        $this->agreement(['retainer_minutes' => 60]);
        $project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic Project', 'status' => 'active', 'is_visible_to_client' => true]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $project->id, 'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-11-02', 'minutes' => 150, 'description' => 'Synthetic work', 'status' => 'approved',
        ]);

        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page
            ->where('this_period.use.overage_minutes', 90)
            ->where('this_period.next_invoice.parts.1.kind', 'overage')
            ->where('this_period.next_invoice.parts.1.hours', 1.5)
            ->where('this_period.next_invoice.parts.1.amount', 30000));
    }

    public function test_a_retainer_already_sold_moves_the_estimate_to_the_next_period(): void
    {
        $agreement = $this->agreement();
        $invoice = $this->invoice('SYN-ADVANCE', ['client_agreement_id' => $agreement->id]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id, 'client_agreement_id' => $agreement->id, 'type' => 'retainer',
            'description' => 'Retainer', 'quantity' => '1.0000', 'unit_amount' => 800000, 'tax_amount' => 0, 'total_amount' => 800000, 'line_date' => '2026-11-01', 'sort_order' => 0,
        ]);
        // A line on another company's invoice must not mark this one as sold.
        $foreign = $this->invoice('SYN-OTHER-ADVANCE', ['client_agreement_id' => $agreement->id], $this->otherCompany);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $foreign->id, 'client_agreement_id' => $agreement->id, 'type' => 'retainer',
            'description' => 'Retainer', 'quantity' => '1.0000', 'unit_amount' => 800000, 'tax_amount' => 0, 'total_amount' => 800000, 'line_date' => '2026-12-01', 'sort_order' => 0,
        ]);

        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page
            ->where('this_period.next_invoice.period_start', '2026-12-01')
            ->where('this_period.next_invoice.total', 800000));
    }

    public function test_an_agreement_the_client_may_not_see_is_not_a_period(): void
    {
        $this->agreement(['title' => 'SECRET hidden agreement', 'is_visible_to_client' => false]);
        $this->agreement(['title' => 'SECRET draft agreement', 'status' => 'draft']);
        $this->agreement(['title' => 'SECRET other company agreement'], $this->otherCompany);
        // Disclosed and not a draft, so the engagement line still names it, but
        // it has ended and so is not this period.
        $this->agreement(['title' => 'Synthetic ended agreement', 'ends_on' => '2026-10-31']);

        $response = $this->home($this->portalUser());

        $response->assertInertia(fn (Assert $page) => $page->where('this_period', null));
        $this->assertStringNotContainsString('SECRET', (string) json_encode($response->viewData('page')['props']));
    }

    public function test_a_project_scoped_client_does_not_see_another_projects_agreement(): void
    {
        $theirs = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Theirs', 'status' => 'active', 'is_visible_to_client' => true]);
        $others = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Others', 'status' => 'active', 'is_visible_to_client' => true]);
        $this->agreement(['title' => 'SECRET scoped to another project', 'client_project_id' => $others->id]);
        $user = $this->portalUser(null, ClientCompanyMembership::SCOPE_PROJECTS);
        $membership = ClientCompanyMembership::query()->where('user_id', $user->id)->firstOrFail();
        DB::table('client_portal_project_access')->insert(['workspace_id' => $this->workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $theirs->id, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->home($user);

        $response->assertInertia(fn (Assert $page) => $page->where('this_period', null));
        $this->assertStringNotContainsString('SECRET', (string) json_encode($response->viewData('page')['props']));
    }

    public function test_deferred_work_follows_the_agreements_setting_through_the_existing_disclosure(): void
    {
        $statement = ['version' => 1, 'entries' => [
            ['id' => 123456, 'workedOn' => '2026-04-01', 'minutes' => 60, 'description' => 'SECRET deferred note', 'projectId' => 1],
            ['id' => 123457, 'workedOn' => '2026-04-02', 'minutes' => 120, 'description' => null, 'projectId' => 1],
        ]];

        foreach ([true, false] as $shown) {
            $agreement = $this->agreement(['show_deferred_backlog_to_client' => $shown]);
            $this->invoice('SYN-DEFERRED-'.($shown ? 'ON' : 'OFF'), ['client_agreement_id' => $agreement->id, 'deferred_work_statement' => $statement, 'issue_date' => $shown ? '2026-10-02' : '2026-10-03']);

            $response = $this->home($this->portalUser());
            if ($shown) {
                $response->assertInertia(fn (Assert $page) => $page
                    ->where('this_period.still_deferred.entry_count', 2)
                    ->where('this_period.still_deferred.total_hours', 3)
                    ->where('this_period.still_deferred.oldest_worked_on', '2026-04-01'));
                // Totals only: the itemised notes stay on the invoice page.
                $this->assertStringNotContainsString('SECRET deferred note', (string) $response->getContent());
                ClientInvoice::query()->where('invoice_number', 'SYN-DEFERRED-ON')->delete();
                $agreement->delete();
            } else {
                $response->assertInertia(fn (Assert $page) => $page->where('this_period.still_deferred', null));
            }
        }
    }

    public function test_deferred_work_is_never_shown_without_an_agreement(): void
    {
        $this->invoice('SYN-NO-AGREEMENT', ['deferred_work_statement' => ['version' => 1, 'entries' => [['id' => 1, 'workedOn' => '2026-04-01', 'minutes' => 60, 'description' => null, 'projectId' => 1]]]]);

        $this->home($this->portalUser())->assertInertia(fn (Assert $page) => $page->where('this_period', null));
    }

    public function test_workspace_internal_fields_never_reach_the_page(): void
    {
        $agreement = $this->agreement(['agreement_text' => 'SECRET internal agreement text']);
        $this->invoice('SYN-INTERNAL', ['notes' => 'SECRET internal invoice note', 'client_agreement_id' => $agreement->id]);
        $project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic Project', 'status' => 'active', 'is_visible_to_client' => true]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $project->id, 'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-11-02', 'minutes' => 60, 'description' => 'SECRET internal time note', 'client_visible_description' => 'Synthetic client summary',
            'is_visible_to_client' => true, 'status' => 'approved', 'billing_rate_amount' => 987654, 'subcontractor_cost_amount' => 765432,
        ]);

        $response = $this->home($this->portalUser());

        $this->assertInertiaPayloadOmits($response, ['SECRET internal', '987654', '765432', 'billing_rate', 'subcontractor', 'hourly_rate', 'lock_version'], 'Synthetic client summary');
    }

    public function test_the_page_does_not_query_once_per_row(): void
    {
        $agreement = $this->agreement();
        ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $this->workspace->id, 'client_agreement_id' => $agreement->id, 'description' => 'Synthetic hosting',
            'cadence' => 'monthly', 'anchor_day' => 20, 'amount' => 15000, 'quantity' => '1.000', 'currency' => 'USD', 'is_active' => true, 'sort_order' => 1,
        ]);
        $count = 0;
        $addRows = function (int $rows) use ($agreement, &$count): void {
            for ($i = 0; $i < $rows; $i++) {
                $count++;
                $invoice = $this->invoice('SYN-ROW-'.$count, ['client_agreement_id' => $agreement->id, 'due_date' => '2026-11-'.str_pad((string) (($count % 20) + 1), 2, '0', STR_PAD_LEFT)]);
                ClientInvoiceLine::query()->create([
                    'workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id, 'client_agreement_id' => $agreement->id, 'type' => 'recurring_item',
                    'description' => 'Hosting', 'quantity' => '1.0000', 'unit_amount' => 15000, 'tax_amount' => 0, 'total_amount' => 15000, 'line_date' => '2026-08-20', 'sort_order' => 0,
                ]);
                ClientInvoicePayment::query()->create(['workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id, 'status' => 'succeeded', 'amount' => 100, 'refunded_amount' => 0, 'currency' => 'USD', 'received_on' => '2026-10-01', 'method' => 'bank_transfer']);
            }
        };
        $addRows(2);
        $user = $this->portalUser();

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($user)->get('/portal/'.$this->company->public_id)->assertOk(),
            fn () => $addRows(8),
        );
    }
}
