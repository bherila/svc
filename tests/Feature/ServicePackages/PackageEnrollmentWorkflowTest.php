<?php

namespace Tests\Feature\ServicePackages;

use App\Exceptions\ServicePackageRefused;
use App\Mail\ServicePackageNoticeMail;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\WorkspaceActionQueue;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\PackageRequestWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Packages\PackageRefusal;
use BWH\Auth\Testing\FakeApplicationInvitations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * A client's enrollment from offer to service (docs/service-packages.md,
 * slice 3a): adding a package from the portal, accepting it and activating it
 * without any payment at sign-up.
 */
final class PackageEnrollmentWorkflowTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
    }

    private function workflow(): PackageEnrollmentWorkflow
    {
        return app(PackageEnrollmentWorkflow::class);
    }

    #[Requirement('ACC-8')]
    public function test_a_company_contact_adds_a_portal_addable_package_as_an_offer_with_no_request(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();

        $enrollment = $this->workflow()->addFromPortal($contact, $company, $package);

        $this->assertSame('offered', $enrollment->status);
        $this->assertNull($enrollment->service_package_request_id);
        $this->assertSame($contact->id, $enrollment->offered_by_user_id);
        $this->assertSame($package->published_version_id, $enrollment->service_package_version_id);
        $project = ClientProject::query()->whereKey($enrollment->client_project_id)->sole();
        $this->assertSame($company->id, $project->client_company_id);
        $this->assertSame('Web hosting · Essentials', $project->name);
        $agreement = ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->sole();
        $this->assertSame('draft', $agreement->status);
        $this->assertSame($project->id, $agreement->client_project_id);

        // Adding again while the offer is open returns that offer, not a second.
        $again = $this->workflow()->addFromPortal($contact, $company, $package);
        $this->assertSame($enrollment->id, $again->id);
        $this->assertSame(1, ServicePackageEnrollment::query()->count());
        $this->assertSame(1, ClientAgreement::query()->count());
    }

    /** @return iterable<string, array{string}> */
    public static function notCompanyContacts(): iterable
    {
        yield 'project-limited contact' => ['projects'];
        yield 'owner previewing the portal' => ['owner'];
        yield 'admin previewing the portal' => ['admin'];
    }

    #[DataProvider('notCompanyContacts')]
    #[Requirement('ACC-8', 'ACC-1')]
    public function test_only_a_company_contact_may_add_or_accept(string $who): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        $other = match ($who) {
            'projects' => $this->contact($company, 'projects'),
            'owner' => $owner,
            default => $this->workspaceMember($workspace, 'admin'),
        };

        $this->assertForbidden(fn () => $this->workflow()->addFromPortal($other, $company, $package));
        $this->assertForbidden(fn () => $this->workflow()->accept($other, $company, $offer, $this->signature()));

        $this->assertSame('offered', $offer->refresh()->status);
        $this->assertNull($offer->accepted_at);
        $this->assertSame(1, ServicePackageEnrollment::query()->count());
    }

    #[Requirement('ACC-8', 'PKG-6')]
    public function test_a_retired_or_unaddable_package_cannot_be_added(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $hidden = $this->publishedPackage($workspace, $owner, ['link_name' => 'hidden-hosting', 'portal_addable' => false]);
        app(ServicePackageWorkflow::class)->retire($owner, $workspace, $package);

        foreach ([$package->refresh(), $hidden] as $refused) {
            try {
                $this->workflow()->addFromPortal($contact, $company, $refused);
                $this->fail('An unaddable package was added.');
            } catch (ServicePackageRefused $refusal) {
                $this->assertSame(PackageRefusal::NotAddable->value, $refusal->refusalCode());
            }
        }
        $this->assertSame(0, ServicePackageEnrollment::query()->count());
        $this->assertSame(0, ClientAgreement::query()->count());
    }

    #[Requirement('ACC-1', 'ACC-2', 'ACC-5', 'ACC-7', 'PKG-7')]
    public function test_accepting_an_automatic_package_records_the_signer_signs_activates_and_emails_the_client(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['retainer_amount' => null]);
        $workspace->forceFill(['timezone' => 'Pacific/Auckland'])->save();
        // 20:00 UTC on Nov 9 is already Nov 10 in Auckland.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 20:00:00', 'UTC'));
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);

        $accepted = $this->workflow()->accept($contact, $company, $offer, $this->signature(' Morgan Synthetic ', ' Owner '));

        $this->assertSame('active', $accepted->status);
        $this->assertSame($contact->id, $accepted->accepted_by_user_id);
        $this->assertSame('Morgan Synthetic', $accepted->signer_name);
        $this->assertSame('Owner', $accepted->signer_title);
        $this->assertSame($package->published_version_id, $accepted->accepted_version_id);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertNotNull($accepted->activated_at);
        $this->assertNull($accepted->activated_by_user_id, 'it started on its own');

        $agreement = ClientAgreement::query()->whereKey($accepted->client_agreement_id)->sole();
        $this->assertSame('active', $agreement->status);
        $this->assertSame('2026-11-10', $agreement->starts_on->toDateString());
        $this->assertNotNull($agreement->signed_at);
        $this->assertSame($contact->id, $agreement->signed_by_user_id);
        $this->assertSame('Morgan Synthetic', $agreement->signer_name);
        $this->assertSame('Owner', $agreement->signer_title);

        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo($contact->email)
            && str_contains($mail->subjectLine, 'is now active') && ! $mail->hasTo($owner->email));
    }

    /**
     * Every term the agreement bills by is on the agreement and its recurring
     * items once accepted, and a later version changes none of it (PKG-7).
     */
    #[Requirement('PKG-7')]
    public function test_the_agreement_carries_every_term_at_acceptance_and_a_later_version_changes_nothing(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world([
            'invoice_issuing' => 'draft', 'hourly_rate_amount' => 14000, 'retainer_amount' => 60000, 'retainer_minutes' => 300, 'rollover_months' => 2,
            'first_cycle_proration' => 'full_period',
        ]);
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        $accepted = $this->workflow()->accept($contact, $company, $offer, $this->signature());
        $agreement = ClientAgreement::query()->whereKey($accepted->client_agreement_id)->sole();
        $snapshot = $this->agreementTerms($agreement);

        $this->assertSame([
            'title' => 'Web hosting · Essentials', 'agreement_text' => 'Synthetic hosting agreement text.', 'billing_cadence' => 'monthly', 'currency' => 'USD',
            'hourly_rate_amount' => 14000, 'retainer_amount' => 60000, 'retainer_minutes' => 300, 'rollover_months' => 2, 'first_cycle_proration' => 'full_period',
            'items' => [['Managed hosting', 'monthly', 4500, false], ['Setup', 'one_time', 15000, false]],
        ], $snapshot);

        app(ServicePackageWorkflow::class)->update($owner, $workspace, $package->refresh(), ['hourly_rate_amount' => 20000, 'components' => [
            ['kind' => 'recurring', 'description' => 'Managed hosting, new price', 'cadence' => 'monthly', 'amount' => 9900],
        ]]);
        app(ServicePackageWorkflow::class)->publish($owner, $workspace, $package->refresh());

        $this->assertSame($snapshot, $this->agreementTerms($agreement->refresh()));
        $this->assertSame($offer->service_package_version_id, $accepted->refresh()->accepted_version_id);
    }

    #[Requirement('ACC-1')]
    public function test_an_offer_made_on_approval_is_accepted_by_the_contact_it_admitted(): void
    {
        FakeApplicationInvitations::swap();
        [$workspace, $owner] = $this->boundPackageWorkspace();
        $package = $this->publishedPackage($workspace, $owner, ['activation_mode' => 'review']);
        $visitor = User::factory()->create(['email' => 'morgan.lakeside@example.com']);
        $visitor->forceFill(['oauth_provider' => 'test-provider', 'oauth_subject' => '4200'])->save();
        $offer = app(PackageRequestWorkflow::class)->approve($owner, $workspace, $this->packageRequest($workspace, $package));
        $company = ClientCompany::query()->whereKey($offer->client_company_id)->sole();

        $accepted = $this->workflow()->accept($visitor, $company, $offer, $this->signature());

        $this->assertSame('awaiting_review', $accepted->status);
        $this->assertSame($visitor->id, $accepted->accepted_by_user_id);
    }

    #[Requirement('ACC-2')]
    public function test_a_repeat_by_the_same_signer_changes_nothing_and_anyone_else_is_refused(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['activation_mode' => 'review']);
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        $first = $this->workflow()->accept($contact, $company, $offer, $this->signature('Morgan Synthetic', 'Owner'));
        $facts = $this->facts($first);

        // The same signer again, from a page loaded before the first acceptance.
        $repeat = $this->workflow()->accept($contact, $company, $offer, $this->signature('Morgan Synthetic', 'Owner'));
        $this->assertSame($facts, $this->facts($repeat));
        $this->assertSame($first->lock_version, $repeat->lock_version, 'a repeat writes nothing');

        $second = $this->contact($company);
        foreach ([[$second, 'Avery Synthetic', null], [$contact, 'Someone Else', 'Owner'], [$contact, 'Morgan Synthetic', 'Director']] as [$who, $name, $title]) {
            try {
                $this->workflow()->accept($who, $company, $offer, $this->signature($name, $title));
                $this->fail('A second, different signature was recorded.');
            } catch (ServicePackageRefused $refused) {
                $this->assertSame(PackageRefusal::AlreadyAccepted->value, $refused->refusalCode());
            }
        }
        $this->assertSame($facts, $this->facts($offer->refresh()));
    }

    #[Requirement('ACC-2')]
    public function test_acceptance_needs_a_name_of_at_most_200_characters_and_the_terms_ticked(): void
    {
        [, , $package, $company, $contact] = $this->world();
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);

        foreach ([
            ['signer_name' => ' ', 'accepted_terms' => true],
            ['signer_name' => str_repeat('n', 201), 'accepted_terms' => true],
            ['signer_name' => 'Morgan', 'signer_title' => str_repeat('t', 201), 'accepted_terms' => true],
            ['signer_name' => 'Morgan', 'accepted_terms' => false],
            ['signer_name' => 'Morgan'],
        ] as $input) {
            try {
                $this->workflow()->accept($contact, $company, $offer, $input);
                $this->fail('An invalid acceptance was recorded: '.json_encode($input));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('offered', $offer->refresh()->status);
    }

    #[Requirement('ACC-5', 'ACC-7')]
    public function test_a_reviewed_package_waits_in_needs_you_until_an_owner_activates_it(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['activation_mode' => 'review']);
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 15:00:00', 'UTC'));

        $accepted = $this->workflow()->accept($contact, $company, $offer, $this->signature());

        $this->assertSame('awaiting_review', $accepted->status);
        $agreement = ClientAgreement::query()->whereKey($accepted->client_agreement_id)->sole();
        $this->assertSame('draft', $agreement->status);
        $this->assertNull($agreement->signed_at);
        Mail::assertNotSent(ServicePackageNoticeMail::class);

        $row = collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_review');
        $this->assertNotNull($row);
        $this->assertSame('enrollment-'.$accepted->public_id, $row['id']);
        $this->assertSame('Web hosting · Essentials to activate', $row['title']);
        $this->assertSame($company->name, $row['client']);
        $this->assertSame(route('clients.agreement', [$workspace, $company, $agreement], absolute: false), $row['href']);
        $this->assertSame('2026-11-09', $row['date']);

        // The service starts on the day it is activated.
        $this->travelTo(CarbonImmutable::parse('2026-11-16 15:00:00', 'UTC'));
        $active = $this->workflow()->activate($owner, $workspace, $accepted, AgentApiVersion::for($accepted->refresh()));

        $this->assertSame('active', $active->status);
        $this->assertSame($owner->id, $active->activated_by_user_id);
        $agreement->refresh();
        $this->assertSame('active', $agreement->status);
        $this->assertSame('2026-11-16', $agreement->starts_on->toDateString());
        $this->assertSame($contact->id, $agreement->signed_by_user_id, 'signed as the contact who accepted, not the owner');
        $this->assertSame('Morgan Synthetic', $agreement->signer_name);
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo($contact->email) && str_contains($mail->subjectLine, 'is now active'));
        $this->assertNull(collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_review'));

        // Again, and nothing changes.
        $lock = $active->refresh()->lock_version;
        $this->workflow()->activate($owner, $workspace, $active);
        $this->assertSame($lock, $active->refresh()->lock_version);
        Mail::assertSentCount(1);
    }

    #[Requirement('ACC-5')]
    public function test_activation_is_refused_before_acceptance_and_to_anyone_but_an_owner_or_admin(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['activation_mode' => 'review']);
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);

        try {
            $this->workflow()->activate($owner, $workspace, $offer);
            $this->fail('An offer nobody accepted was activated.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::NotAwaitingReview->value, $refused->refusalCode());
        }

        $accepted = $this->workflow()->accept($contact, $company, $offer, $this->signature());
        foreach ([$this->workspaceMember($workspace, 'member'), $contact] as $who) {
            try {
                $this->workflow()->activate($who, $workspace, $accepted);
                $this->fail('A non-manager activated a service.');
            } catch (HttpException $missing) {
                $this->assertSame(404, $missing->getStatusCode());
            }
        }
        $this->assertSame('awaiting_review', $accepted->refresh()->status);
    }

    #[Requirement('ACC-5')]
    public function test_an_overlapping_agreement_refuses_activation_with_a_code_and_holds_an_automatic_one_for_review(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['activation_mode' => 'review']);
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        $accepted = $this->workflow()->accept($contact, $company, $offer, $this->signature());
        $this->activeAgreementOn($workspace, $company, $accepted->client_project_id);

        try {
            $this->workflow()->activate($owner, $workspace, $accepted);
            $this->fail('An overlapping agreement was activated.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::ActivationConflict->value, $refused->refusalCode());
        }
        $this->assertSame('awaiting_review', $accepted->refresh()->status);
        $this->assertSame('draft', ClientAgreement::query()->whereKey($accepted->client_agreement_id)->value('status'));

        // An automatic package whose agreement cannot start keeps the signature and waits for review.
        $automatic = $this->publishedPackage($workspace, $owner, ['link_name' => 'automatic-hosting']);
        $second = $this->workflow()->addFromPortal($contact, $company, $automatic);
        $this->activeAgreementOn($workspace, $company, $second->client_project_id);
        $held = $this->workflow()->accept($contact, $company, $second, $this->signature());
        $this->assertSame('awaiting_review', $held->status);
        $this->assertSame('Morgan Synthetic', $held->refresh()->signer_name);
        $this->assertNull($held->activated_at);
        $this->assertSame('draft', ClientAgreement::query()->whereKey($held->client_agreement_id)->value('status'));
        Mail::assertNotSent(ServicePackageNoticeMail::class);
    }

    #[Requirement('EDGE-7')]
    public function test_acceptance_signs_the_currently_published_version_and_says_it_changed(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        $offered = $offer->service_package_version_id;
        app(ServicePackageWorkflow::class)->update($owner, $workspace, $package->refresh(), ['agreement_text' => 'Synthetic agreement, second edition.', 'components' => [
            ['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 5500],
        ]]);
        $current = app(ServicePackageWorkflow::class)->publish($owner, $workspace, $package->refresh())->published_version_id;

        $accepted = $this->workflow()->accept($contact, $company, $offer, $this->signature());

        $this->assertSame($offered, $accepted->service_package_version_id, 'the offer is recorded as made');
        $this->assertSame($current, $accepted->accepted_version_id);
        $agreement = ClientAgreement::query()->whereKey($accepted->client_agreement_id)->sole();
        $this->assertSame('Synthetic agreement, second edition.', $agreement->agreement_text);
        $this->assertSame([['Managed hosting', 'monthly', 5500, false]], $this->agreementTerms($agreement)['items']);
    }

    #[Requirement('EDGE-8')]
    public function test_a_retired_packages_open_offer_is_still_accepted_on_its_offered_terms(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        app(ServicePackageWorkflow::class)->retire($owner, $workspace, $package->refresh());

        $accepted = $this->workflow()->accept($contact, $company, $offer, $this->signature());

        $this->assertSame('active', $accepted->status);
        $this->assertSame($offer->service_package_version_id, $accepted->accepted_version_id);
    }

    #[Requirement('EDGE-3')]
    public function test_a_contact_who_lost_access_cannot_accept_and_a_recorded_signature_stays(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['activation_mode' => 'review']);
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        $leaving = $this->contact($company);
        // The page was loaded; access is withdrawn before the form is sent.
        ClientCompanyMembership::query()->where('client_company_id', $company->id)->where('user_id', $leaving->id)->delete();

        $this->assertForbidden(fn () => $this->workflow()->accept($leaving, $company, $offer, $this->signature('Avery Synthetic')));
        $this->assertSame('offered', $offer->refresh()->status);
        $this->assertNull($offer->accepted_at);

        // A recorded signature outlives the signer's access.
        $accepted = $this->workflow()->accept($contact, $company, $offer, $this->signature());
        ClientCompanyMembership::query()->where('client_company_id', $company->id)->where('user_id', $contact->id)->update(['access_scope' => 'projects']);
        $active = $this->workflow()->activate($owner, $workspace, $accepted);
        $this->assertSame('active', $active->status);
        $this->assertSame('Morgan Synthetic', $active->signer_name);
        $this->assertSame($contact->id, ClientAgreement::query()->whereKey($active->client_agreement_id)->value('signed_by_user_id'));
    }

    /** A version that takes a payment at sign-up waits for slice 3b; nothing half-works. */
    public function test_a_version_that_takes_a_payment_is_refused_when_added_and_when_accepted(): void
    {
        $this->connectStripe();
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        app(ServicePackageWorkflow::class)->update($owner, $workspace, $package->refresh(), ['charge_first_period' => true]);
        app(ServicePackageWorkflow::class)->publish($owner, $workspace, $package->refresh());
        $paying = $this->publishedPackage($workspace, $owner, ['link_name' => 'paid-hosting', 'require_autopay' => true, 'autopay_limit_amount' => 50000]);

        foreach ([
            fn () => $this->workflow()->accept($contact, $company, $offer, $this->signature()),
            fn () => $this->workflow()->addFromPortal($contact, $company, $paying),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A sign-up that takes a payment went ahead.');
            } catch (ServicePackageRefused $refused) {
                $this->assertSame(PackageRefusal::PaymentNotAvailableYet->value, $refused->refusalCode());
            }
        }
        $this->assertSame('offered', $offer->refresh()->status);
        $this->assertNull($offer->accepted_at);
        $this->assertSame('draft', ClientAgreement::query()->whereKey($offer->client_agreement_id)->value('status'));
        $this->assertSame(1, ServicePackageEnrollment::query()->count());
    }

    public function test_another_companys_or_workspaces_offer_cannot_be_accepted_or_activated(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);
        $neighbour = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Hillside Synthetic Florist', 'slug' => 'hillside-'.Str::uuid()]);
        $neighbourContact = $this->contact($neighbour);
        [$other, $otherOwner] = $this->packageWorkspace('Synthetic Studio Two');
        $otherPackage = $this->publishedPackage($other, $otherOwner);

        foreach ([
            fn () => $this->workflow()->accept($neighbourContact, $neighbour, $offer, $this->signature()),
            fn () => $this->workflow()->addFromPortal($contact, $company, $otherPackage),
            fn () => $this->workflow()->activate($otherOwner, $other, $offer),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A record of another company or workspace was reached.');
            } catch (HttpException|ModelNotFoundException $missing) {
                // Either way the caller is answered 404.
                $this->assertTrue($missing instanceof ModelNotFoundException || $missing->getStatusCode() === 404);
            }
        }
        $this->assertSame('offered', $offer->refresh()->status);
        $this->assertSame(1, ServicePackageEnrollment::query()->count());
    }

    /** A rolled-back acceptance leaves nothing behind and tells nobody. */
    public function test_a_rolled_back_acceptance_sends_no_email(): void
    {
        [, , $package, $company, $contact] = $this->world();
        $offer = $this->workflow()->addFromPortal($contact, $company, $package);

        try {
            DB::transaction(function () use ($contact, $company, $offer): void {
                $this->workflow()->accept($contact, $company, $offer, $this->signature());
                throw new \RuntimeException('synthetic failure after acceptance');
            });
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('offered', $offer->refresh()->status);
        Mail::assertNotSent(ServicePackageNoticeMail::class);
    }

    /**
     * @param  array<string, mixed>  $overrides  the package's terms
     * @return array{0: Workspace, 1: User, 2: ServicePackage, 3: ClientCompany, 4: User}
     */
    private function world(array $overrides = []): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner, $overrides);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => 'lakeside-'.Str::uuid()]);

        return [$workspace, $owner, $package, $company, $this->contact($company)];
    }

    private function contact(ClientCompany $company, string $scope = 'company'): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => $scope]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function signature(string $name = 'Morgan Synthetic', ?string $title = null): array
    {
        return ['signer_name' => $name, 'signer_title' => $title, 'accepted_terms' => true];
    }

    private function activeAgreementOn(Workspace $workspace, ClientCompany $company, int $projectId): void
    {
        $project = ClientProject::query()->whereKey($projectId)->sole();
        $agreement = app(AgreementWorkflow::class)->create($workspace, $company, $project, null, [
            'title' => 'Synthetic overlapping agreement', 'starts_on' => '2026-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly',
        ]);
        app(AgreementWorkflow::class)->activate($agreement);
    }

    private function assertForbidden(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Someone who is not a company contact acted for the company.');
        } catch (HttpException $refused) {
            $this->assertSame(403, $refused->getStatusCode());
        }
    }

    /** @return array<string, mixed> */
    private function facts(ServicePackageEnrollment $enrollment): array
    {
        return [$enrollment->status, $enrollment->accepted_at?->toIso8601String(), $enrollment->accepted_by_user_id, $enrollment->signer_name, $enrollment->signer_title, $enrollment->accepted_version_id];
    }

    /** @return array<string, mixed> */
    private function agreementTerms(ClientAgreement $agreement): array
    {
        return [
            ...$agreement->only(['title', 'agreement_text', 'billing_cadence', 'currency', 'hourly_rate_amount', 'retainer_amount', 'retainer_minutes', 'rollover_months', 'first_cycle_proration']),
            'items' => ClientAgreementRecurringItem::query()->where('client_agreement_id', $agreement->id)->orderBy('sort_order')->get()
                ->map(fn (ClientAgreementRecurringItem $item): array => [$item->description, $item->cadence, $item->amount, (bool) $item->is_taxable])->all(),
        ];
    }
}
