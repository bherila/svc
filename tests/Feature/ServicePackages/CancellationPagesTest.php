<?php

namespace Tests\Feature\ServicePackages;

use App\Models\AgreementCancellationRequest;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\WorkspaceActionQueue;
use App\Services\Engagement\AgreementCancellations;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BootsServicePackages;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * The cancellation screens (docs/service-packages.md, slice 5): the portal's
 * panel on a service and an agreement with its three browser writes, the
 * agreement page's panel for owners and admins, and the Needs you row. Every
 * date and URL is the server's; a control is offered exactly when it may be
 * used.
 *
 * The clock starts at 9 November 2026 in a UTC workspace.
 */
final class CancellationPagesTest extends TestCase
{
    use BootsServicePackages;
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
    }

    #[Requirement('CAN-2', 'CAN-3', 'EDGE-11')]
    public function test_a_company_contact_is_offered_the_server_dates_and_what_is_still_owed(): void
    {
        [$workspace, , $company, $contact, $enrollment, $agreement] = $this->world('request');
        $company->invoices()->create([
            'workspace_id' => $workspace->id, 'client_agreement_id' => $agreement->id, 'invoice_number' => 'SYN-OVERDUE-1', 'status' => 'issued', 'currency' => 'USD',
            'subtotal_amount' => 12000, 'tax_amount' => 0, 'total_amount' => 12000, 'balance_amount' => 12000, 'is_visible_to_client' => true,
            'issue_date' => '2026-09-01', 'due_date' => '2026-09-15', 'issued_at' => now(), 'hours_billed_at_rate' => 0,
        ]);
        $this->actingAs($contact);

        $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/service')
            ->where('cancellation.mode', 'request')
            ->where('cancellation.offered_end_dates', ['2026-12-31', '2027-01-31', '2027-02-28'])
            ->where('cancellation.earliest_end_on', '2026-12-31')
            ->where('cancellation.cancel_url', "/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation")
            ->where('cancellation.withdraw_url', null)
            ->where('cancellation.undo_url', null)
            ->where('cancellation.provider_name', $workspace->name)
            // EDGE-11: shown, and never a reason to refuse.
            ->where('cancellation.balance.amount_due', 12000)
            ->where('cancellation.balance.overdue', true));

        $this->post("/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation", ['end_date' => '2027-01-31', 'reason' => 'Budget review.'])
            ->assertRedirect("/portal/{$company->public_id}/services/{$enrollment->public_id}");
        $this->assertSame(['pending', '2027-01-31'], [AgreementCancellationRequest::query()->sole()->status, AgreementCancellationRequest::query()->sole()->requested_end_on->toDateString()]);

        $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cancellation.pending_request.requested_end_on', '2027-01-31')
            ->where('cancellation.offered_end_dates', [])
            ->where('cancellation.cancel_url', null)
            ->where('cancellation.withdraw_url', "/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation/withdraw"));

        $this->post("/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation/withdraw")->assertRedirect();
        $this->assertSame('withdrawn', AgreementCancellationRequest::query()->sole()->status);
    }

    #[Requirement('CAN-2', 'CAN-3')]
    public function test_a_date_not_offered_is_refused_as_a_field_error(): void
    {
        [, , $company, $contact, , $agreement] = $this->world('self_serve');
        $this->actingAs($contact);

        $this->from("/portal/{$company->public_id}/agreements/{$agreement->public_id}")
            ->post("/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation", ['end_date' => '2026-12-15'])
            ->assertSessionHasErrors(['end_date' => 'That end date is not one of the dates offered. Choose one of the offered dates.']);
        $this->post("/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation", ['end_date' => 'December'])->assertSessionHasErrors('end_date');
        $this->assertSame(0, AgreementScheduledTermination::query()->count());
    }

    #[Requirement('CAN-3', 'EDGE-9')]
    public function test_a_self_serve_end_is_scheduled_shown_and_undone_from_the_agreement_page(): void
    {
        [, , $company, $contact, , $agreement] = $this->world('self_serve');
        $this->actingAs($contact);

        $this->post("/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation", ['end_date' => '2026-12-31'])->assertRedirect();
        $this->assertSame('2026-12-31', $agreement->refresh()->ends_on?->toDateString());

        $this->get("/portal/{$company->public_id}/agreements/{$agreement->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('clients/agreement')
            ->where('agreement.notice_days', 30)
            ->where('agreement.cancellation_mode', 'self_serve')
            ->where('portal_cancellation.scheduled_end.ends_on', '2026-12-31')
            ->where('portal_cancellation.cancelled_by_client', true)
            ->where('portal_cancellation.undo_url', "/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation/undo"));

        $this->post("/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation/undo")->assertRedirect();
        $this->assertNull($agreement->refresh()->ends_on);
    }

    /** Project-limited contacts and staff previewing read the panel, with no URL to act. */
    #[Requirement('CAN-3', 'CAN-4')]
    public function test_only_a_company_contact_is_given_the_urls_and_may_post(): void
    {
        [$workspace, $owner, $company, $contact, $enrollment, $agreement] = $this->world('self_serve');
        app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
        $limited = $this->contact($company, 'projects');
        DB::table('client_portal_project_access')->insert([
            'workspace_id' => $workspace->id,
            'client_company_membership_id' => ClientCompanyMembership::query()->where('user_id', $limited->id)->sole()->id,
            'client_project_id' => $enrollment->client_project_id,
        ]);

        foreach ([$limited, $owner] as $viewer) {
            $this->actingAs($viewer);
            $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cancellation.scheduled_end.ends_on', '2026-12-31')
                ->where('cancellation.undo_url', null)
                ->where('cancellation.balance', null));
            $this->post("/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation/undo")->assertForbidden();
        }
        $this->assertSame('2026-12-31', $agreement->refresh()->ends_on?->toDateString());
    }

    #[Requirement('CAN-3', 'X-1')]
    public function test_none_mode_shows_nothing_and_the_routes_are_gone_while_the_feature_is_off(): void
    {
        [, , $company, $contact, $enrollment, $agreement] = $this->world('none');
        $this->actingAs($contact);
        $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('cancellation', null));

        config(['svc.service_packages.enabled' => false]);
        $this->get("/portal/{$company->public_id}/agreements/{$agreement->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('portal_cancellation', null));
        $this->post("/portal/{$company->public_id}/agreements/{$agreement->public_id}/cancellation", ['end_date' => '2026-12-31'])->assertNotFound();
    }

    /** Another company's agreement, named through this company, is not found. */
    #[Requirement('CAN-3')]
    public function test_another_companys_agreement_is_not_reached_through_this_company(): void
    {
        [$workspace, , $company, $contact] = $this->world('self_serve');
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Harbor Synthetic Florist', 'slug' => 'harbor-'.Str::uuid()]);
        $theirs = $this->agreement($workspace, $otherCompany, 'self_serve');
        $this->actingAs($contact);

        $this->post("/portal/{$company->public_id}/agreements/{$theirs->public_id}/cancellation", ['end_date' => '2026-12-31'])->assertNotFound();
        $this->assertNull($theirs->refresh()->ends_on);
    }

    #[Requirement('CAN-4', 'EDGE-9', 'CAN-1')]
    public function test_the_agreement_page_gives_owners_the_decision_with_server_dates(): void
    {
        [$workspace, $owner, $company, $contact, , $agreement] = $this->world('request');
        $request = app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, '2027-01-31', 'Moving to a new agency next quarter.');
        $this->actingAs($owner);

        $this->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('agreement.notice_days', 30)
            ->where('agreement.minimum_term_months', null)
            ->where('agreement.cancellation_mode', 'request')
            ->where('operator_cancellation.pending.requested_end_on', '2027-01-31')
            ->where('operator_cancellation.pending.reason', 'Moving to a new agency next quarter.')
            ->where('operator_cancellation.pending.version', AgentApiVersion::for($request))
            ->where('operator_cancellation.pending.options', [
                ['value' => '2026-11-30', 'waives_notice' => true],
                ['value' => '2026-12-31', 'waives_notice' => false],
                ['value' => '2027-01-31', 'waives_notice' => false],
                ['value' => '2027-02-28', 'waives_notice' => false],
            ])
            ->where('operator_cancellation.pending.approve_href', "/api/v1/workspaces/{$workspace->public_id}/cancellation-requests/{$request->public_id}/approve")
            ->where('operator_cancellation.pending.decline_href', "/api/v1/workspaces/{$workspace->public_id}/cancellation-requests/{$request->public_id}/decline")
            ->where('operator_cancellation.scheduled', null)
            ->where('operator_cancellation.schedule', null));

        app(AgreementCancellations::class)->approve($owner, $workspace, $request, null, false, null);
        $this->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('operator_cancellation.pending', null)
            ->where('operator_cancellation.scheduled.ends_on', '2027-01-31')
            ->where('operator_cancellation.scheduled.version', AgentApiVersion::for($agreement->refresh()))
            ->where('operator_cancellation.scheduled.undo_href', "/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/scheduled-end/undo")
            ->where('operator_cancellation.schedule', null));
    }

    #[Requirement('CAN-4', 'CAN-6')]
    public function test_a_pending_request_is_in_needs_you_until_it_is_decided(): void
    {
        [$workspace, $owner, $company, $contact, , $agreement] = $this->world('request');
        $request = app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, '2027-01-31', null);

        $row = collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'cancellation_request');
        $this->assertSame([
            'id' => 'cancellation-request-'.$request->public_id, 'kind' => 'cancellation_request', 'title' => 'Web hosting · Essentials: cancellation requested',
            'client' => $company->name, 'href' => "/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}",
            'action' => 'Review', 'amount' => null, 'currency' => null, 'date' => '2027-01-31', 'count' => null, 'minutes' => null,
        ], $row);

        app(AgreementCancellations::class)->decline($owner, $workspace, $request, null);
        $this->assertNull(collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'cancellation_request'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: Workspace, 1: User, 2: ClientCompany, 3: User, 4: ServicePackageEnrollment, 5: ClientAgreement} */
    private function world(string $mode): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $package = $this->publishedPackage($workspace, $owner, ['cancellation_mode' => $mode, 'notice_days' => 30, 'invoice_issuing' => 'draft']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => 'lakeside-'.Str::uuid(), 'billing_email' => 'billing-lakeside@example.com']);
        $contact = $this->contact($company);
        $workflow = app(PackageEnrollmentWorkflow::class);
        $enrollment = $workflow->accept($contact, $company, $offer = $workflow->addFromPortal($contact, $company, $package), $this->signatureFor($offer))->refresh();

        return [$workspace->refresh(), $owner, $company, $contact, $enrollment, ClientAgreement::query()->findOrFail($enrollment->client_agreement_id)];
    }

    private function agreement(Workspace $workspace, ClientCompany $company, string $mode): ClientAgreement
    {
        $workflow = app(AgreementWorkflow::class);
        $agreement = $workflow->create($workspace, $company, null, null, ['title' => 'Synthetic retainer', 'starts_on' => '2026-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'is_visible_to_client' => true]);

        return $workflow->activate($workflow->update($workspace, $agreement, ['notice_days' => 30, 'cancellation_mode' => $mode]))->refresh();
    }

    private function contact(ClientCompany $company, string $scope = 'company'): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => $scope]);

        return $user;
    }
}
