<?php

namespace Tests\Feature\ServicePackages;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\Packages\PackageRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * The portal's Services tab and its review-and-sign page: finished URLs only,
 * an add or accept link exactly when the viewer may use it, and the two
 * browser writes (ACC-8, ACC-1).
 */
final class PortalServicesPagesTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
    }

    #[Requirement('ACC-8')]
    public function test_a_company_contact_sees_their_services_and_what_they_can_add(): void
    {
        $this->connectStripe();
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $paying = $this->publishedPackage($workspace, $owner, ['link_name' => 'paid-hosting', 'require_autopay' => true, 'autopay_limit_amount' => 50000]);
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        $this->actingAs($contact);

        $this->get("/portal/{$company->public_id}/services")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/services')
            ->where('company.id', $company->public_id)
            ->has('services', 1)
            ->where('services.0.id', $offer->public_id)
            ->where('services.0.status', 'offered')
            ->where('services.0.name', 'Web hosting · Essentials')
            ->where('services.0.url', url("/portal/{$company->public_id}/services/{$offer->public_id}"))
            ->where('services.0.agreement_url', null)
            ->where('services.0.terms.cycle_amount', 4500)
            ->has('addable_packages', 2)
            ->where('addable_packages.0.id', $package->public_id)
            ->where('addable_packages.0.add_url', "/portal/{$company->public_id}/services")
            ->where('addable_packages.0.add_refusal', null)
            ->where('addable_packages.1.id', $paying->public_id)
            // A sign-up that takes a payment is offered while Stripe is connected.
            ->where('addable_packages.1.add_url', "/portal/{$company->public_id}/services")
            ->where('addable_packages.1.add_refusal', null)
            ->where('workspaceNavigation.clients.0.destinations.services', "/portal/{$company->public_id}/services"));
    }

    #[Requirement('ACC-8')]
    public function test_a_project_limited_contact_sees_only_their_projects_services_and_cannot_add(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $mine = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        $other = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $this->publishedPackage($workspace, $owner, ['link_name' => 'second-hosting']));
        $limited = $this->contact($company, 'projects', [$mine->client_project_id]);
        $this->actingAs($limited);

        $this->get("/portal/{$company->public_id}/services")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('services', 1)
            ->where('services.0.id', $mine->public_id)
            ->where('addable_packages', null));
        $this->get("/portal/{$company->public_id}/services/{$mine->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.id', $mine->public_id)
            ->where('accept_url', null));
        $this->get("/portal/{$company->public_id}/services/{$other->public_id}")->assertNotFound();

        $this->post("/portal/{$company->public_id}/services", ['package' => $package->public_id])->assertForbidden();
        $this->post("/portal/{$company->public_id}/services/{$mine->public_id}/accept", $this->signatureFor($mine))->assertForbidden();
        $this->assertSame('offered', $mine->refresh()->status);
        $this->assertSame(2, ServicePackageEnrollment::query()->count());
    }

    #[Requirement('ACC-8')]
    public function test_staff_previewing_the_portal_see_the_services_but_may_not_add_or_sign(): void
    {
        [, $owner, $package, $company, $contact] = $this->world();
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        $this->actingAs($owner);

        $this->get("/portal/{$company->public_id}/services")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('services', 1)
            ->where('addable_packages', null));
        $this->get("/portal/{$company->public_id}/services/{$offer->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accept_url', null)
            ->where('accept_fields', null));
        $this->post("/portal/{$company->public_id}/services", ['package' => $package->public_id])->assertForbidden();
        $this->post("/portal/{$company->public_id}/services/{$offer->public_id}/accept", $this->signatureFor($offer))->assertForbidden();
        $this->assertSame('offered', $offer->refresh()->status);
    }

    #[Requirement('ACC-8', 'ACC-1', 'ACC-2')]
    public function test_a_company_contact_adds_reviews_and_signs_in_the_browser(): void
    {
        [, , $package, $company, $contact] = $this->world();
        $this->actingAs($contact);

        $added = $this->post("/portal/{$company->public_id}/services", ['package' => $package->public_id]);
        $enrollment = ServicePackageEnrollment::query()->sole();
        $added->assertRedirect("/portal/{$company->public_id}/services/{$enrollment->public_id}");
        $this->assertNull($enrollment->service_package_request_id);

        $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/service')
            ->where('service.status', 'offered')
            ->where('service.terms.agreement_text', 'Synthetic hosting agreement text.')
            ->where('service.terms.activation_mode', 'automatic')
            ->where('service.acceptance', null)
            ->where('offered_terms', null)
            ->where('changed_terms', [])
            ->where('accept_refusal', null)
            ->where('accept_url', "/portal/{$company->public_id}/services/{$enrollment->public_id}/accept"));

        $this->post("/portal/{$company->public_id}/services/{$enrollment->public_id}/accept", ['signer_name' => 'Morgan Synthetic'])
            ->assertSessionHasErrors('accepted_terms');
        $this->assertSame('offered', $enrollment->refresh()->status);

        $this->post("/portal/{$company->public_id}/services/{$enrollment->public_id}/accept", $this->signatureFor($enrollment))
            ->assertRedirect("/portal/{$company->public_id}/services/{$enrollment->public_id}");
        $this->assertSame('active', $enrollment->refresh()->status);

        $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.status', 'active')
            ->where('service.acceptance.signer_name', 'Morgan Synthetic')
            ->where('service.version_changed', false)
            ->where('service.agreement_url', url("/portal/{$company->public_id}/agreements/".$enrollment->agreement->public_id))
            ->where('accept_url', null));
    }

    #[Requirement('EDGE-7')]
    public function test_the_review_page_shows_what_changed_when_a_newer_version_was_published(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        app(ServicePackageWorkflow::class)->update($owner, $workspace, $package->refresh(), ['notice_days' => 60, 'agreement_text' => 'Synthetic agreement, second edition.']);
        app(ServicePackageWorkflow::class)->publish($owner, $workspace, $package->refresh());
        $this->actingAs($contact);

        $this->get("/portal/{$company->public_id}/services/{$offer->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.version_number', 2)
            ->where('service.terms.notice_days', 60)
            ->where('offered_terms.notice_days', 30)
            ->where('changed_terms', ['agreement_text', 'notice_days']));

        $this->post("/portal/{$company->public_id}/services/{$offer->public_id}/accept", $this->signatureFor($offer))->assertRedirect();
        $this->get("/portal/{$company->public_id}/services/{$offer->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.version_number', 2)
            ->where('service.version_changed', true));
    }

    /**
     * EDGE-7, ACC-2: the client signs the version they reviewed. A version
     * published between opening the page and signing - new agreement text and
     * a new sign-up charge - is refused on the form with nothing written, and
     * the reloaded page shows it and what changed; signing that one takes it.
     */
    #[Requirement('EDGE-7', 'ACC-2')]
    public function test_a_version_published_while_the_client_reviews_is_refused_until_they_review_it(): void
    {
        $this->connectStripe();
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        $page = "/portal/{$company->public_id}/services/{$offer->public_id}";
        $first = ServicePackageVersion::query()->whereKey($package->refresh()->published_version_id)->sole()->public_id;
        $this->actingAs($contact)->get($page)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accept_fields', ['reviewed_version' => $first]));

        app(ServicePackageWorkflow::class)->update($owner, $workspace, $package->refresh(), ['agreement_text' => 'Synthetic agreement, second edition.', 'charge_setup_fee_at_signup' => true]);
        app(ServicePackageWorkflow::class)->publish($owner, $workspace, $package->refresh());
        $second = ServicePackageVersion::query()->whereKey($package->refresh()->published_version_id)->sole()->public_id;
        $this->assertNotSame($first, $second);

        $this->from($page)->post("{$page}/accept", ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true, 'reviewed_version' => $first])
            ->assertRedirect($page)
            ->assertSessionHasErrors(['reviewed_version' => PackageRefusal::VersionChanged->message()]);
        $offer->refresh();
        $this->assertSame(['offered', null, null, null], [$offer->status, $offer->accepted_at, $offer->accepted_version_id, $offer->signup_invoice_id]);
        $this->assertSame(0, ClientInvoice::query()->count());
        $this->assertSame('Synthetic hosting agreement text.', ClientAgreement::query()->whereKey($offer->client_agreement_id)->value('agreement_text'));
        // Sent with nothing at all, it is refused the same way.
        $this->post("{$page}/accept", ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true])
            ->assertSessionHasErrors(['reviewed_version' => PackageRefusal::VersionChanged->message()]);
        $this->assertSame('offered', $offer->refresh()->status);

        $this->get($page)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.terms.agreement_text', 'Synthetic agreement, second edition.')
            ->where('changed_terms', ['agreement_text', 'charge_setup_fee_at_signup'])
            ->where('checkout.due_now.total', 15000)
            ->where('accept_fields', ['reviewed_version' => $second]));
        $this->post("{$page}/accept", ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true, 'reviewed_version' => $second])
            ->assertSessionHasNoErrors()
            ->assertRedirect($page);
        $offer->refresh();
        $this->assertSame((int) $package->published_version_id, $offer->accepted_version_id);
        $this->assertSame('Synthetic agreement, second edition.', ClientAgreement::query()->whereKey($offer->client_agreement_id)->value('agreement_text'));
        $this->assertSame(15000, ClientInvoice::query()->whereKey($offer->signup_invoice_id)->sole()->total_amount);
    }

    public function test_a_refused_acceptance_is_shown_on_the_form_and_changes_nothing(): void
    {
        $this->connectStripe();
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        app(ServicePackageWorkflow::class)->update($owner, $workspace, $package->refresh(), ['charge_first_period' => true]);
        app(ServicePackageWorkflow::class)->publish($owner, $workspace, $package->refresh());
        // The Stripe connection it was published with has gone since.
        $this->disconnectStripe();
        $this->actingAs($contact);

        $this->get("/portal/{$company->public_id}/services/{$offer->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accept_url', null)
            ->where('accept_refusal.code', 'package_payment_unavailable'));
        $this->post("/portal/{$company->public_id}/services/{$offer->public_id}/accept", $this->signatureFor($offer))->assertSessionHasErrors('acceptance');
        $this->assertSame('offered', $offer->refresh()->status);
    }

    public function test_another_companys_or_workspaces_services_are_not_found(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        $neighbour = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Hillside Synthetic Florist', 'slug' => 'hillside-'.Str::uuid()]);
        $neighbourContact = $this->contact($neighbour);
        [$other, $otherOwner] = $this->packageWorkspace('Synthetic Studio Two');
        $foreignPackage = $this->publishedPackage($other, $otherOwner);
        $this->actingAs($neighbourContact);

        $this->get("/portal/{$neighbour->public_id}/services")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('services', 0));
        $this->get("/portal/{$neighbour->public_id}/services/{$offer->public_id}")->assertNotFound();
        $this->post("/portal/{$neighbour->public_id}/services/{$offer->public_id}/accept", $this->signatureFor($offer))->assertNotFound();
        $this->post("/portal/{$neighbour->public_id}/services", ['package' => $foreignPackage->public_id])->assertNotFound();
        $this->get("/portal/{$company->public_id}/services")->assertNotFound();
        $this->assertSame('offered', $offer->refresh()->status);
        $this->assertSame(1, ServicePackageEnrollment::query()->count());
    }

    #[Requirement('X-1')]
    public function test_with_the_feature_off_no_services_page_or_tab_exists(): void
    {
        [, , $package, $company, $contact] = $this->world();
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        config(['svc.service_packages.enabled' => false]);
        $this->actingAs($contact);

        $this->get("/portal/{$company->public_id}/services")->assertNotFound();
        $this->get("/portal/{$company->public_id}/services/{$offer->public_id}")->assertNotFound();
        $this->post("/portal/{$company->public_id}/services", ['package' => $package->public_id])->assertNotFound();
        $this->post("/portal/{$company->public_id}/services/{$offer->public_id}/accept", $this->signatureFor($offer))->assertNotFound();
        $this->get("/portal/{$company->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaceNavigation.clients.0.destinations.services', null));
        $this->assertSame('offered', $offer->refresh()->status);
    }

    /** @return array{0: Workspace, 1: User, 2: ServicePackage, 3: ClientCompany, 4: User} */
    private function world(): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => 'lakeside-'.Str::uuid()]);

        return [$workspace, $owner, $package, $company, $this->contact($company)];
    }

    /** @param list<int> $projectIds */
    private function contact(ClientCompany $company, string $scope = 'company', array $projectIds = []): User
    {
        $user = User::factory()->create();
        $membership = ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => $scope]);
        foreach ($projectIds as $projectId) {
            DB::table('client_portal_project_access')->insert([
                'workspace_id' => $company->workspace_id,
                'client_company_membership_id' => $membership->id,
                'client_project_id' => ClientProject::query()->whereKey($projectId)->value('id'),
            ]);
        }

        return $user;
    }
}
