<?php

namespace Tests\Feature\ServicePackages;

use App\Exceptions\AgreementCancellationRefused;
use App\Mail\ServicePackageNoticeMail;
use App\Models\AgreementCancellationRequest;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\AgreementCancellations;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Engagement\Cancellation\CancellationRefusal;
use App\Support\Engagement\Cancellation\CancellationTerms;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Cancellation from the domain down (docs/service-packages.md, slice 5): the
 * terms every agreement carries, the end dates offered, a client's self-serve
 * end or request, an owner's decision, undoing a scheduled end, and the
 * scheduled termination on the date.
 *
 * The clock starts at 9 November 2026, 12:00 UTC, in a UTC workspace.
 */
final class AgreementCancellationTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
    }

    private function cancellations(): AgreementCancellations
    {
        return app(AgreementCancellations::class);
    }

    // ── CAN-1: terms on every agreement ─────────────────────────────────────

    #[Requirement('CAN-1')]
    public function test_any_agreement_carries_cancellation_terms_editable_through_its_update(): void
    {
        [$workspace, , $company] = $this->world();
        $agreement = app(AgreementWorkflow::class)->create($workspace, $company, null, null, [
            'title' => 'Synthetic retainer', 'starts_on' => '2026-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly',
        ])->refresh();
        // Any agreement not made from a package offers nothing in the portal.
        $this->assertSame([0, null, 'none'], [$agreement->notice_days, $agreement->minimum_term_months, $agreement->cancellation_mode]);

        $updated = app(AgreementWorkflow::class)->update($workspace, $agreement, ['notice_days' => 45, 'minimum_term_months' => 6, 'cancellation_mode' => 'request']);

        $this->assertSame([45, 6, 'request'], [$updated->notice_days, $updated->minimum_term_months, $updated->cancellation_mode]);
        $activity = ClientCompanyActivity::query()->where('action', 'agreement.updated')->latest('id')->firstOrFail();
        $this->assertSame(['old' => 0, 'new' => 45], $activity->payload['changes']['notice_days']);
    }

    #[Requirement('CAN-1')]
    public function test_a_package_agreement_copies_its_versions_cancellation_terms(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner, ['notice_days' => 14, 'minimum_term_months' => 3, 'cancellation_mode' => 'request', 'invoice_issuing' => 'draft']);
        $company = $this->company($workspace);
        $contact = $this->contact($company);
        $enrollment = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);

        $agreement = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->assertSame([14, 3, 'request'], [$agreement->notice_days, $agreement->minimum_term_months, $agreement->cancellation_mode]);
    }

    // ── CAN-2: only offered dates ───────────────────────────────────────────

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function cadences(): iterable
    {
        // Notice runs out on Dec 9: the close of the period it falls in, and the closes after it.
        yield 'monthly' => ['monthly', '2026-01-01', ['2026-12-31', '2027-01-31', '2027-02-28']];
        // Quarters anchored on the start: Oct 15 - Jan 14 holds Dec 9.
        yield 'quarterly' => ['quarterly', '2026-01-15', ['2027-01-14', '2027-04-14', '2027-07-14']];
    }

    /** @param list<string> $offered */
    #[DataProvider('cadences')]
    #[Requirement('CAN-2')]
    public function test_only_the_offered_period_closes_are_accepted(string $cadence, string $startsOn, array $offered): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['billing_cadence' => $cadence, 'starts_on' => $startsOn, 'cancellation_mode' => 'request']);

        $this->assertSame($offered, $this->offered($workspace, $agreement));
        foreach (['2026-11-30', '2026-12-15', '2026-12-09', '2028-12-31'] as $other) {
            $this->assertRefused(CancellationRefusal::DateNotOffered, fn () => $this->cancellations()->cancelByContact($contact, $company, $agreement, $other, null));
        }
        $this->assertSame(0, AgreementCancellationRequest::query()->count());

        $request = $this->cancellations()->cancelByContact($contact, $company, $agreement, $offered[1], null);
        $this->assertInstanceOf(AgreementCancellationRequest::class, $request);
        $this->assertSame($offered[1], $request->requested_end_on->toDateString());
    }

    #[Requirement('CAN-2')]
    public function test_the_minimum_term_holds_the_earliest_end_back(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['starts_on' => '2026-10-01', 'minimum_term_months' => 6]);

        // Six months from Oct 1 runs to Mar 31; notice alone would allow Dec 31.
        $this->assertSame(['2027-03-31', '2027-04-30', '2027-05-31'], $this->offered($workspace, $agreement));
        $this->assertRefused(CancellationRefusal::DateNotOffered, fn () => $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null));
    }

    // ── CAN-3: what a company contact's cancellation does ───────────────────

    #[Requirement('CAN-3', 'EDGE-9', 'CAN-6')]
    public function test_a_self_serve_cancellation_schedules_the_end_at_once_and_keeps_the_agreement_active(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve']);

        $end = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', 'Moving the site in-house.');

        $this->assertInstanceOf(AgreementScheduledTermination::class, $end);
        $agreement->refresh();
        $this->assertSame(['active', '2026-12-31', null], [$agreement->status, $agreement->ends_on?->toDateString(), $agreement->terminated_at]);
        $this->assertSame(['scheduled', 'self_serve', '2026-12-31', null, $contact->id], [$end->status, $end->source, $end->ends_on->toDateString(), $end->previous_ends_on, $end->scheduled_by_user_id]);
        $this->assertSame(0, AgreementCancellationRequest::query()->count());
        $this->assertTrue(ClientCompanyActivity::query()->where('action', 'agreement.end_scheduled')->exists());
        // CAN-6: owners and admins hear it was scheduled; the client gets the date.
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('owner@example.com') && $mail->heading === 'Cancellation scheduled'
            && str_contains(implode(' ', $mail->paragraphs), 'Moving the site in-house.'));
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('billing-lakeside@example.com') && $mail->subjectLine === 'Synthetic hosting ends on Dec 31, 2026');
    }

    #[Requirement('CAN-3', 'CAN-6')]
    public function test_a_request_mode_cancellation_waits_for_an_owner_and_changes_nothing_yet(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'request']);

        $request = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2027-01-31', '  Budget review.  ');

        $this->assertInstanceOf(AgreementCancellationRequest::class, $request);
        $this->assertSame(['pending', '2027-01-31', 'Budget review.', $contact->id], [$request->status, $request->requested_end_on->toDateString(), $request->reason, $request->requested_by_user_id]);
        $this->assertNull($agreement->refresh()->ends_on);
        $this->assertSame(0, AgreementScheduledTermination::query()->count());
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('owner@example.com') && $mail->heading === 'Cancellation requested');
        Mail::assertNotSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('billing-lakeside@example.com'));

        // One request at a time.
        $this->assertRefused(CancellationRefusal::RequestPending, fn () => $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null));
    }

    #[Requirement('CAN-3')]
    public function test_none_mode_offers_no_cancellation_and_only_an_active_recurring_agreement_is_cancelled(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $none = $this->agreement($workspace, $company, ['cancellation_mode' => 'none']);
        $this->assertRefused(CancellationRefusal::NotOffered, fn () => $this->cancellations()->cancelByContact($contact, $company, $none, '2026-12-31', null));

        $oneTime = $this->agreement($workspace, $company, ['billing_cadence' => 'one_time', 'cancellation_mode' => 'self_serve'], project: true);
        $this->assertRefused(CancellationRefusal::NotRecurring, fn () => $this->cancellations()->cancelByContact($contact, $company, $oneTime, '2026-12-31', null));

        $draft = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve'], activate: false, project: true);
        $this->assertRefused(CancellationRefusal::AgreementNotActive, fn () => $this->cancellations()->cancelByContact($contact, $company, $draft, '2026-12-31', null));
        $this->assertSame(0, AgreementScheduledTermination::query()->count() + AgreementCancellationRequest::query()->count());
    }

    /** @return iterable<string, array{string}> */
    public static function notCompanyContacts(): iterable
    {
        yield 'project-limited contact' => ['projects'];
        yield 'owner previewing the portal' => ['owner'];
    }

    #[DataProvider('notCompanyContacts')]
    #[Requirement('CAN-3', 'CAN-4')]
    public function test_only_a_company_contact_cancels_withdraws_or_undoes_for_the_company(string $who): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $requested = $this->agreement($workspace, $company, ['cancellation_mode' => 'request']);
        $selfServe = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve'], project: true);
        $this->cancellations()->cancelByContact($contact, $company, $requested, '2026-12-31', null);
        $this->cancellations()->cancelByContact($contact, $company, $selfServe, '2026-12-31', null);
        $other = $who === 'owner' ? $owner : $this->contact($company, 'projects');

        foreach ([
            fn () => $this->cancellations()->cancelByContact($other, $company, $selfServe, '2027-01-31', null),
            fn () => $this->cancellations()->withdrawByContact($other, $company, $requested),
            fn () => $this->cancellations()->undoByContact($other, $company, $selfServe),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Someone who is not a company contact acted for the company.');
            } catch (HttpException $refused) {
                $this->assertSame(403, $refused->getStatusCode());
            }
        }
        $this->assertSame('pending', AgreementCancellationRequest::query()->sole()->status);
        $this->assertSame('scheduled', AgreementScheduledTermination::query()->sole()->status);
    }

    // ── CAN-4: withdraw, approve, decline ───────────────────────────────────

    #[Requirement('CAN-4')]
    public function test_a_company_contact_withdraws_a_pending_request(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'request']);
        $request = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);

        $withdrawn = $this->cancellations()->withdrawByContact($contact, $company, $agreement);

        $this->assertSame([$request->id, 'withdrawn', $contact->id], [$withdrawn->id, $withdrawn->status, $withdrawn->decided_by_user_id]);
        $this->assertRefused(CancellationRefusal::RequestNotPending, fn () => $this->cancellations()->withdrawByContact($contact, $company, $agreement));
        $this->assertRefused(CancellationRefusal::RequestNotPending, fn () => $this->cancellations()->approve($owner, $workspace, $request, null, false, null));
        // Withdrawn, the client may ask again.
        $this->assertInstanceOf(AgreementCancellationRequest::class, $this->cancellations()->cancelByContact($contact, $company, $agreement, '2027-01-31', null));
    }

    #[Requirement('CAN-4', 'EDGE-9', 'CAN-6')]
    public function test_an_owner_approves_on_the_requested_date_and_the_client_is_told_it(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'request', 'ends_on' => '2027-12-31']);
        $request = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2027-01-31', null);
        Mail::fake();

        $approved = $this->cancellations()->approve($owner, $workspace, $request, null, false, 'Thanks for working with us.', AgentApiVersion::for($request->refresh()));

        $this->assertSame(['approved', '2027-01-31', false, 'Thanks for working with us.', $owner->id], [$approved->status, $approved->decided_end_on?->toDateString(), $approved->notice_waived, $approved->decision_note, $approved->decided_by_user_id]);
        $end = AgreementScheduledTermination::query()->sole();
        $this->assertSame(['scheduled', 'request', '2027-01-31', '2027-12-31', $request->id], [$end->status, $end->source, $end->ends_on->toDateString(), $end->previous_ends_on?->toDateString(), $end->agreement_cancellation_request_id]);
        $this->assertSame(['active', '2027-01-31'], [$agreement->refresh()->status, $agreement->ends_on?->toDateString()]);
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('billing-lakeside@example.com') && $mail->heading === 'Your cancellation is confirmed'
            && str_contains(implode(' ', $mail->paragraphs), 'Jan 31, 2027') && str_contains(implode(' ', $mail->paragraphs), 'Thanks for working with us.'));
        $this->assertRefused(CancellationRefusal::RequestNotPending, fn () => $this->cancellations()->decline($owner, $workspace, $request, null));
    }

    #[Requirement('CAN-4', 'CAN-2')]
    public function test_an_owner_approves_on_another_offered_date_or_waives_notice_but_never_off_the_offer(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'request']);
        $request = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2027-01-31', null);

        // Nov 30 is offered only once the 30 days' notice is waived.
        $this->assertRefused(CancellationRefusal::DateNotOffered, fn () => $this->cancellations()->approve($owner, $workspace, $request, '2026-11-30', false, null));
        $this->assertRefused(CancellationRefusal::DateNotOffered, fn () => $this->cancellations()->approve($owner, $workspace, $request, '2026-11-20', true, null));
        $this->assertSame('pending', $request->refresh()->status);

        $approved = $this->cancellations()->approve($owner, $workspace, $request, '2026-11-30', true, null);

        $this->assertSame(['2026-11-30', true], [$approved->decided_end_on?->toDateString(), $approved->notice_waived]);
        $this->assertSame('2026-11-30', $agreement->refresh()->ends_on?->toDateString());
    }

    #[Requirement('CAN-4', 'CAN-6')]
    public function test_an_owner_declines_with_a_note_the_client_reads(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'request']);
        $request = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);

        $declined = $this->cancellations()->decline($owner, $workspace, $request, 'Your minimum term runs to March.');

        $this->assertSame(['declined', 'Your minimum term runs to March.'], [$declined->status, $declined->decision_note]);
        $this->assertNull($agreement->refresh()->ends_on);
        $this->assertSame(0, AgreementScheduledTermination::query()->count());
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('billing-lakeside@example.com') && $mail->heading === 'Cancellation request declined'
            && str_contains(implode(' ', $mail->paragraphs), 'Your minimum term runs to March.'));
    }

    #[Requirement('CAN-4')]
    public function test_a_decision_needs_an_owner_or_admin_and_the_current_version(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'request']);
        $request = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
        $member = $this->workspaceMember($workspace, 'member');

        foreach ([$member, $contact] as $notManager) {
            try {
                $this->cancellations()->approve($notManager, $workspace, $request, null, false, null);
                $this->fail('Someone who is not an owner or admin decided a cancellation.');
            } catch (HttpException $refused) {
                $this->assertSame(404, $refused->getStatusCode());
            }
        }
        try {
            $this->cancellations()->decline($owner, $workspace, $request, null, str_repeat('0', 64));
            $this->fail('A stale version decided a request.');
        } catch (HttpException $conflict) {
            $this->assertSame(409, $conflict->getStatusCode());
        }
        $this->assertSame('pending', $request->refresh()->status);
    }

    // ── EDGE-9: a scheduled end is undone until its date ────────────────────

    #[Requirement('EDGE-9', 'CAN-6')]
    public function test_a_company_contact_undoes_their_own_self_serve_end_which_restores_the_previous_end(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve', 'ends_on' => '2027-06-30']);
        $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
        Mail::fake();

        $undone = $this->cancellations()->undoByContact($contact, $company, $agreement);

        $this->assertSame(['undone', $contact->id], [$undone->status, $undone->undone_by_user_id]);
        $this->assertSame(['active', '2027-06-30'], [$agreement->refresh()->status, $agreement->ends_on?->toDateString()]);
        $this->assertTrue(ClientCompanyActivity::query()->where('action', 'agreement.end_undone')->exists());
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('owner@example.com') && $mail->heading === 'Cancellation undone');
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('billing-lakeside@example.com') && $mail->heading === 'Cancellation undone');
        $this->assertRefused(CancellationRefusal::NotScheduled, fn () => $this->cancellations()->undoByContact($contact, $company, $agreement));
        // And may cancel again.
        $this->assertInstanceOf(AgreementScheduledTermination::class, $this->cancellations()->cancelByContact($contact, $company, $agreement, '2027-01-31', null));
    }

    #[Requirement('EDGE-9')]
    public function test_an_end_an_owner_decided_is_undone_only_by_an_owner_or_admin(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'request']);
        $request = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
        $this->cancellations()->approve($owner, $workspace, $request, null, false, null);

        $this->assertRefused(CancellationRefusal::UndoNotSelfServe, fn () => $this->cancellations()->undoByContact($contact, $company, $agreement));
        // Even once the portal mode becomes self-serve: the owner decided this one.
        DB::table('client_agreements')->where('id', $agreement->id)->update(['cancellation_mode' => 'self_serve']);
        $this->assertRefused(CancellationRefusal::UndoNotSelfServe, fn () => $this->cancellations()->undoByContact($contact, $company, $agreement));

        $undone = $this->cancellations()->undo($owner, $workspace, $agreement->refresh(), AgentApiVersion::for($agreement));
        $this->assertSame('undone', $undone->status);
        $this->assertNull($agreement->refresh()->ends_on);
    }

    #[Requirement('EDGE-9')]
    public function test_an_owner_schedules_and_undoes_an_end_in_any_mode(): void
    {
        [$workspace, $owner, $company] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'none']);

        $end = $this->cancellations()->schedule($owner, $workspace, $agreement, '2026-11-30', true, AgentApiVersion::for($agreement));

        $this->assertSame(['operator', '2026-11-30'], [$end->source, $agreement->refresh()->ends_on?->toDateString()]);
        $this->assertRefused(CancellationRefusal::AlreadyScheduled, fn () => $this->cancellations()->schedule($owner, $workspace, $agreement, '2026-12-31', false));
        $this->cancellations()->undo($owner, $workspace, $agreement);
        $this->assertNull($agreement->refresh()->ends_on);
    }

    #[Requirement('EDGE-9')]
    public function test_the_end_date_cannot_be_edited_under_a_scheduled_end_nor_undone_after_it(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve']);
        $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);

        $this->assertRefused(CancellationRefusal::EndScheduled, fn () => app(AgreementWorkflow::class)->update($workspace, $agreement, ['ends_on' => '2027-03-31']));
        // Other terms stay editable.
        app(AgreementWorkflow::class)->update($workspace, $agreement, ['title' => 'Synthetic hosting (ending)']);

        // The scheduler missed the date: the end has passed and stays.
        $this->travelTo(CarbonImmutable::parse('2027-01-01 08:00:00', 'UTC'));
        $this->assertRefused(CancellationRefusal::EndReached, fn () => $this->cancellations()->undo($owner, $workspace, $agreement));
        $this->assertSame('2026-12-31', $agreement->refresh()->ends_on?->toDateString());
    }

    // ── CAN-5: terminated on the date ───────────────────────────────────────

    #[Requirement('CAN-5', 'EDGE-9')]
    public function test_the_scheduled_command_terminates_on_the_end_date_through_the_existing_termination(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve']);
        $end = $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);

        $this->travelTo(CarbonImmutable::parse('2026-12-30 23:30:00', 'UTC'));
        $this->assertSame([], $this->terminateRun()['outcomes']);
        $this->assertSame('active', $agreement->refresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-12-31 00:20:00', 'UTC'));
        $preview = $this->terminateRun(apply: false);
        $this->assertSame([['workspace_id' => $workspace->public_id, 'scheduled_end_id' => $end->public_id, 'ends_on' => '2026-12-31', 'outcome' => 'terminated']], $preview['outcomes']);
        $this->assertSame('active', $agreement->refresh()->status);

        $run = $this->terminateRun();
        $this->assertSame('terminated', $run['outcomes'][0]['outcome']);
        $agreement->refresh();
        $this->assertSame(['terminated', '2026-12-31', '2026-12-31 00:20:00'], [$agreement->status, $agreement->ends_on?->toDateString(), $agreement->terminated_at?->utc()->toDateTimeString()]);
        $this->assertSame(['terminated', '2026-12-31 00:20:00'], [$end->refresh()->status, $end->terminated_at?->toDateTimeString()]);
        $activity = ClientCompanyActivity::query()->where('action', 'agreement.transitioned')->sole();
        $this->assertSame(['old' => 'active', 'new' => 'terminated'], $activity->payload['changes']['status']);

        // Once is enough; an undo afterwards finds nothing to undo.
        $this->assertSame([], $this->terminateRun()['outcomes']);
        $this->assertRefused(CancellationRefusal::NotScheduled, fn () => $this->cancellations()->undoByContact($contact, $company, $agreement));
    }

    #[Requirement('CAN-5')]
    public function test_the_end_date_is_reached_on_the_workspaces_own_calendar(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $workspace->forceFill(['timezone' => 'America/Los_Angeles'])->save();
        $agreement = $this->agreement($workspace->refresh(), $company, ['cancellation_mode' => 'self_serve']);
        $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);

        // 05:00 UTC on the 31st is still the 30th in Los Angeles.
        $this->travelTo(CarbonImmutable::parse('2026-12-31 05:00:00', 'UTC'));
        $this->assertSame([], $this->terminateRun()['outcomes']);
        $this->travelTo(CarbonImmutable::parse('2026-12-31 09:00:00', 'UTC'));
        $this->assertSame('terminated', $this->terminateRun()['outcomes'][0]['outcome']);
        $this->assertSame('terminated', $agreement->refresh()->status);
    }

    /** Every scheduled end is reached, a page at a time: none waits behind the first page. */
    #[Requirement('CAN-5')]
    public function test_every_due_end_is_reached_whatever_the_page_size(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        [$other, $otherOwner] = $this->packageWorkspace('Synthetic Studio Two');
        $other->forceFill(['timezone' => 'UTC'])->save();
        $otherCompany = $this->company($other, 'harbor');
        $otherContact = $this->contact($otherCompany);
        $agreements = [];
        foreach ([[$workspace, $company, $contact], [$other, $otherCompany, $otherContact], [$workspace, $company, $contact]] as $index => [$space, $client, $person]) {
            $agreement = $this->agreement($space, $client, ['cancellation_mode' => 'self_serve'], project: $index > 0);
            $this->cancellations()->cancelByContact($person, $client, $agreement, '2026-12-31', null);
            $agreements[] = $agreement;
        }
        // A future end on the first page does not hold the rest back.
        $later = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve'], project: true);
        $this->cancellations()->cancelByContact($contact, $company, $later, '2027-01-31', null);

        $this->travelTo(CarbonImmutable::parse('2026-12-31 01:00:00', 'UTC'));
        $run = $this->terminateRun(['--page' => 1]);

        $this->assertCount(3, $run['outcomes']);
        foreach ($agreements as $agreement) {
            $this->assertSame('terminated', $agreement->refresh()->status);
        }
        $this->assertSame('active', $later->refresh()->status);
    }

    #[Requirement('CAN-5', 'X-1')]
    public function test_the_command_is_scheduled_hourly_and_does_nothing_while_the_feature_is_off(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve']);
        $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
        $this->travelTo(CarbonImmutable::parse('2026-12-31 01:00:00', 'UTC'));
        config(['svc.service_packages.enabled' => false]);

        $this->assertSame([], $this->terminateRun()['outcomes']);
        $this->assertSame('active', $agreement->refresh()->status);
        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => str_contains((string) $event->command, 'svc:agreements:terminate-scheduled --apply'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertFalse($event->filtersPass($this->app));
        config(['svc.service_packages.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    /** EDGE-9: an undo and the termination on the date are decided under one lock; whichever runs first wins. */
    #[Requirement('EDGE-9', 'CAN-5')]
    public function test_an_undo_and_the_scheduled_termination_settle_each_other(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        $undoneFirst = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve']);
        $terminatedFirst = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve'], project: true);
        $undoneEnd = $this->cancellations()->cancelByContact($contact, $company, $undoneFirst, '2026-12-31', null);
        $terminatedEnd = $this->cancellations()->cancelByContact($contact, $company, $terminatedFirst, '2026-12-31', null);
        $this->travelTo(CarbonImmutable::parse('2026-12-31 00:05:00', 'UTC'));

        // Both read as due by the run; the undo commits before the run reaches its lock.
        $this->cancellations()->undo($owner, $workspace, $undoneFirst);
        $this->assertSame('not_due', $this->cancellations()->terminateDue($workspace, $undoneEnd)->value);
        $this->assertSame(['active', null], [$undoneFirst->refresh()->status, $undoneFirst->ends_on]);

        $this->assertSame('terminated', $this->cancellations()->terminateDue($workspace, $terminatedEnd)->value);
        $this->assertRefused(CancellationRefusal::NotScheduled, fn () => $this->cancellations()->undo($owner, $workspace, $terminatedFirst));
        $this->assertSame('terminated', $terminatedFirst->refresh()->status);
    }

    /** Terminating by hand carries out a scheduled end and closes a pending request, so nothing waits on an ended agreement. */
    #[Requirement('CAN-5')]
    public function test_terminating_by_hand_writes_terminated_at_and_settles_what_was_open(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $requested = $this->agreement($workspace, $company, ['cancellation_mode' => 'request']);
        $scheduled = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve'], project: true);
        $request = $this->cancellations()->cancelByContact($contact, $company, $requested, '2026-12-31', null);
        $end = $this->cancellations()->cancelByContact($contact, $company, $scheduled, '2026-12-31', null);

        app(AgreementWorkflow::class)->terminate($requested);
        app(AgreementWorkflow::class)->terminate($scheduled, '2026-11-15');

        $this->assertSame('2026-11-09 12:00:00', $requested->refresh()->terminated_at?->toDateTimeString());
        $this->assertSame(['declined', 'The agreement was terminated.'], [$request->refresh()->status, $request->decision_note]);
        $this->assertSame(['terminated', '2026-11-15'], [$end->refresh()->status, $scheduled->refresh()->ends_on?->toDateString()]);
    }

    // ── EDGE-11: a balance owed never blocks ────────────────────────────────

    #[Requirement('EDGE-11')]
    public function test_an_overdue_balance_never_blocks_a_cancellation_and_stays_owed(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve']);
        $invoice = $company->invoices()->create([
            'workspace_id' => $workspace->id, 'client_agreement_id' => $agreement->id, 'invoice_number' => 'SYN-OVERDUE-1', 'status' => 'issued', 'currency' => 'USD',
            'subtotal_amount' => 12000, 'tax_amount' => 0, 'total_amount' => 12000, 'balance_amount' => 12000,
            'issue_date' => '2026-09-01', 'due_date' => '2026-09-15', 'issued_at' => now(), 'hours_billed_at_rate' => 0,
        ]);

        $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);

        $this->assertSame('2026-12-31', $agreement->refresh()->ends_on?->toDateString());
        $this->assertSame(['issued', 12000], [$invoice->refresh()->status, (int) $invoice->balance_amount]);
    }

    // ── Isolation and after-commit ──────────────────────────────────────────

    #[Requirement('CAN-3', 'CAN-4')]
    public function test_another_workspaces_agreement_and_request_are_out_of_reach(): void
    {
        [$workspace, $owner, $company, $contact] = $this->world();
        [$other, $otherOwner] = $this->packageWorkspace('Synthetic Studio Two');
        $other->forceFill(['timezone' => 'UTC'])->save();
        $theirCompany = $this->company($other, 'harbor');
        $theirs = $this->agreement($other, $theirCompany, ['cancellation_mode' => 'request']);
        $theirRequest = $this->cancellations()->cancelByContact($this->contact($theirCompany), $theirCompany, $theirs, '2026-12-31', null);

        // Our contact, naming their agreement through our company.
        $this->assertStatus(404, fn () => $this->cancellations()->cancelByContact($contact, $company, $theirs, '2026-12-31', null));
        // Our owner, deciding their request or ending their agreement in our workspace.
        $this->assertNotFound(fn () => $this->cancellations()->approve($owner, $workspace, $theirRequest, null, false, null));
        $this->assertNotFound(fn () => $this->cancellations()->schedule($owner, $workspace, $theirs, '2026-12-31', false));
        // Their owner, in their own workspace, but not ours.
        $this->assertStatus(404, fn () => $this->cancellations()->schedule($otherOwner, $workspace, $theirs, '2026-12-31', false));

        $this->assertSame('pending', $theirRequest->refresh()->status);
        $this->assertNull($theirs->refresh()->ends_on);
        $this->assertSame(1, AgreementCancellationRequest::query()->where('workspace_id', $other->id)->count());
        $this->assertSame(0, AgreementCancellationRequest::query()->where('workspace_id', $workspace->id)->count());
    }

    /** X-5: a cancellation rolled back sends nothing. */
    #[Requirement('CAN-6', 'X-5')]
    public function test_a_rolled_back_cancellation_sends_no_email(): void
    {
        [$workspace, , $company, $contact] = $this->world();
        $agreement = $this->agreement($workspace, $company, ['cancellation_mode' => 'self_serve']);

        try {
            DB::transaction(function () use ($contact, $company, $agreement): void {
                $this->cancellations()->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
                throw new RuntimeException('Synthetic failure after the cancellation.');
            });
        } catch (RuntimeException) {
        }

        Mail::assertNothingSent();
        $this->assertNull($agreement->refresh()->ends_on);
        $this->assertSame(0, AgreementScheduledTermination::query()->count());
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: Workspace, 1: User, 2: ClientCompany, 3: User} */
    private function world(): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $owner->forceFill(['email' => 'owner@example.com'])->save();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $company = $this->company($workspace->refresh());

        return [$workspace, $owner, $company, $this->contact($company)];
    }

    private function company(Workspace $workspace, string $slug = 'lakeside'): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => $slug.'-'.Str::uuid(),
            'billing_email' => "billing-{$slug}@example.com",
        ]);
    }

    private function contact(ClientCompany $company, string $scope = 'company'): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => $scope]);

        return $user;
    }

    /** @param array<string, mixed> $terms */
    private function agreement(Workspace $workspace, ClientCompany $company, array $terms = [], bool $activate = true, bool $project = false): ClientAgreement
    {
        $workflow = app(AgreementWorkflow::class);
        // A second agreement of the company gets its own project, so the two never overlap.
        $scope = $project ? $company->projects()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic project '.Str::random(6)]) : null;
        $agreement = $workflow->create($workspace, $company, $scope, null, [
            'title' => 'Synthetic hosting', 'starts_on' => $terms['starts_on'] ?? '2026-01-01', 'currency' => 'USD',
            'billing_cadence' => $terms['billing_cadence'] ?? 'monthly', 'is_visible_to_client' => true,
        ]);
        $agreement = $workflow->update($workspace, $agreement, [
            'notice_days' => 30, 'cancellation_mode' => 'self_serve',
            ...array_diff_key($terms, array_flip(['starts_on', 'billing_cadence'])),
        ]);

        return ($activate ? $workflow->activate($agreement) : $agreement)->refresh();
    }

    /** @return list<string> */
    private function offered(Workspace $workspace, ClientAgreement $agreement): array
    {
        return CancellationTerms::of($agreement)->offer(CarbonImmutable::parse(now($workspace->timezone)->toDateString()))?->offeredDateStrings() ?? [];
    }

    private function assertRefused(CancellationRefusal $refusal, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected the refusal {$refusal->value}.");
        } catch (AgreementCancellationRefused $refused) {
            $this->assertSame($refusal->value, $refused->refusalCode());
        }
    }

    private function assertStatus(int $status, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected HTTP {$status}.");
        } catch (HttpException $refused) {
            $this->assertSame($status, $refused->getStatusCode());
        }
    }

    private function assertNotFound(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Expected not found.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{dry_run: bool, executed: bool, note: string|null, outcomes: list<array{workspace_id: string, scheduled_end_id: string, ends_on: string, outcome: string}>}
     */
    private function terminateRun(array $options = [], bool $apply = true): array
    {
        $exit = Artisan::call('svc:agreements:terminate-scheduled', [...($apply ? ['--apply' => true] : []), '--format' => 'json', ...$options]);
        $output = trim(Artisan::output());
        $this->assertSame(0, $exit, $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
