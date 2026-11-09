<?php

namespace Tests\Feature\AgentApi;

use App\Mail\ServicePackageNoticeMail;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\AgreementCancellations;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BootsServicePackages;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\CallsMcp;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Cancellation over the agent API (docs/service-packages.md, "MCP and REST
 * surface"): owners and admins read and decide requests and schedule or undo
 * an end with `confirm` and the current version; a client's contacts read
 * where their service's cancellation stands through `client_services.*` and
 * act only in the portal.
 *
 * The clock starts at 9 November 2026 in a UTC workspace.
 */
final class AgentCancellationApiTest extends TestCase
{
    use BootsServicePackages;
    use BuildsServicePackages;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'billing:read', 'clients:read', 'clients:write', 'packages:read'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
    }

    #[Requirement('API-1', 'CAN-4', 'CAN-6')]
    public function test_an_owner_lists_reads_and_approves_a_request_over_rest_and_mcp(): void
    {
        [$workspace, $owner, $company, $contact, $agreement] = $this->world('request');
        $request = app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, '2027-01-31', 'Moving to a new agency next quarter.');
        $this->actingAsMcp($owner, self::SCOPES);

        $list = $this->getJson($this->url($workspace))->assertOk();
        $this->schema('cancellation_requests.list', $list->json());
        $list->assertJsonPath('data.0.id', $request->public_id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.client', ['id' => $company->public_id, 'name' => $company->name])
            ->assertJsonPath('data.0.agreement.id', $agreement->public_id)
            ->assertJsonPath('data.0.agreement.url', url("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}"))
            ->assertJsonPath('data.0.requested_end_on', '2027-01-31')
            ->assertJsonPath('data.0.reason', 'Moving to a new agency next quarter.')
            ->assertJsonPath('data.0.offered_end_dates', ['2026-12-31', '2027-01-31', '2027-02-28'])
            ->assertJsonPath('data.0.offered_end_dates_without_notice', ['2026-11-30', '2026-12-31', '2027-01-31']);
        $this->assertSame([], $this->getJson($this->url($workspace).'?status=approved')->assertOk()->json('data'));
        $read = $this->getJson($this->url($workspace, $request->public_id))->assertOk();
        $this->schema('cancellation_requests.get', $read->json());
        $this->assertSame($list->json('data.0'), $read->json('data'));

        $session = $this->initialize();
        $got = $this->callTool($session, 'cancellation_requests.get', ['workspace_id' => $workspace->public_id, 'cancellation_request_id' => $request->public_id]);
        $this->assertSame($read->json(), $got['result']['structuredContent']);

        // agreements.get carries the terms and the pending request.
        $agreementRead = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}")->assertOk();
        $this->schema('agreements.get', $agreementRead->json());
        $agreementRead->assertJsonPath('data.notice_days', 30)
            ->assertJsonPath('data.cancellation_mode', 'request')
            ->assertJsonPath('data.cancellation.pending_request.id', $request->public_id)
            ->assertJsonPath('data.cancellation.scheduled_end', null);

        $body = ['expected_version' => $read->json('data.version'), 'confirm' => true, 'end_date' => '2026-11-30', 'waive_notice' => true, 'note' => 'Thanks for working with us.'];
        $approved = $this->postJson($this->url($workspace, $request->public_id).'/approve', $body, ['Idempotency-Key' => 'synthetic-approve'])->assertOk();
        $this->schema('cancellation_requests.approve', $approved->json());
        $approved->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_end_on', '2026-11-30')
            ->assertJsonPath('data.notice_waived', true)
            ->assertJsonPath('data.offered_end_dates', []);
        $this->assertSame(['active', '2026-11-30'], [$agreement->refresh()->status, $agreement->ends_on?->toDateString()]);
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo('billing-lakeside@example.com') && $mail->heading === 'Your cancellation is confirmed');

        // The same call over MCP is a replay: one approval, one email.
        $replay = $this->callTool($session, 'cancellation_requests.approve', ['workspace_id' => $workspace->public_id, 'cancellation_request_id' => $request->public_id, 'idempotency_key' => 'synthetic-approve', ...$body]);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($approved->json(), $replay['result']['structuredContent']);
        $this->assertSame(1, AgreementScheduledTermination::query()->count());
        Mail::assertSentCount(2);
    }

    #[Requirement('API-1', 'EDGE-9', 'CAN-4')]
    public function test_an_owner_schedules_and_undoes_an_end_and_declines_a_request(): void
    {
        [$workspace, $owner, $company, $contact, $agreement] = $this->world('none');
        $this->actingAsMcp($owner, self::SCOPES);
        $agreementUrl = "/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}";
        $read = $this->getJson($agreementUrl)->assertOk();
        $read->assertJsonPath('data.cancellation.earliest_end_on', '2026-12-31')
            ->assertJsonPath('data.cancellation.offered_end_dates_without_notice.0', '2026-11-30');

        $scheduled = $this->postJson($agreementUrl.'/scheduled-end', ['expected_version' => $read->json('data.version'), 'confirm' => true, 'end_date' => '2026-12-31'], ['Idempotency-Key' => 'synthetic-schedule'])->assertOk();
        $this->schema('agreements.schedule_end', $scheduled->json());
        $scheduled->assertJsonPath('data.ends_on', '2026-12-31')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.cancellation.scheduled_end.source', 'operator')
            ->assertJsonPath('data.cancellation.offered_end_dates', []);

        $undone = $this->callTool($this->initialize(), 'agreements.undo_scheduled_end', ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id, 'idempotency_key' => 'synthetic-undo', 'expected_version' => $scheduled->json('data.version'), 'confirm' => true]);
        $this->assertFalse($undone['result']['isError'] ?? true, json_encode($undone));
        $this->assertNull($undone['result']['structuredContent']['data']['ends_on']);
        $this->assertNull($undone['result']['structuredContent']['data']['cancellation']['scheduled_end']);

        // A request in another mode, declined with a note.
        app(AgreementWorkflow::class)->update($workspace, $agreement->refresh(), ['cancellation_mode' => 'request']);
        $request = app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement->refresh(), '2026-12-31', null);
        $declined = $this->postJson($this->url($workspace, $request->public_id).'/decline', ['expected_version' => AgentApiVersion::for($request), 'confirm' => true, 'note' => 'Your minimum term runs to March.'], ['Idempotency-Key' => 'synthetic-decline'])->assertOk();
        $this->schema('cancellation_requests.decline', $declined->json());
        $declined->assertJsonPath('data.status', 'declined')->assertJsonPath('data.decision_note', 'Your minimum term runs to March.');
    }

    #[Requirement('CAN-2', 'CAN-4')]
    public function test_a_refusal_carries_its_code_and_changes_nothing(): void
    {
        [$workspace, $owner, $company, $contact, $agreement] = $this->world('request');
        $request = app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, '2027-01-31', null);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->postJson($this->url($workspace, $request->public_id).'/approve', ['expected_version' => AgentApiVersion::for($request), 'confirm' => true, 'end_date' => '2026-11-30'], ['Idempotency-Key' => 'synthetic-off-offer'])
            ->assertUnprocessable()->assertJsonPath('code', 'cancellation_date_not_offered');
        $this->postJson("/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/scheduled-end", ['expected_version' => AgentApiVersion::for($agreement->refresh()), 'confirm' => true, 'end_date' => '2026-12-31'], ['Idempotency-Key' => 'synthetic-pending'])
            ->assertUnprocessable()->assertJsonPath('code', 'cancellation_request_pending');
        $this->postJson($this->url($workspace, $request->public_id).'/approve', ['expected_version' => str_repeat('0', 64), 'confirm' => true], ['Idempotency-Key' => 'synthetic-stale'])->assertConflict();
        $this->postJson("/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/scheduled-end", ['expected_version' => AgentApiVersion::for($agreement->refresh()), 'confirm' => true], ['Idempotency-Key' => 'synthetic-no-date'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');

        $this->assertSame('pending', $request->refresh()->status);
        $this->assertNull($agreement->refresh()->ends_on);
    }

    /** @return iterable<string, array{string, string}> */
    public static function confirmedWrites(): iterable
    {
        yield 'approve' => ['cancellation_requests.approve', 'request'];
        yield 'decline' => ['cancellation_requests.decline', 'request'];
        yield 'schedule an end' => ['agreements.schedule_end', 'agreement'];
        yield 'undo an end' => ['agreements.undo_scheduled_end', 'agreement'];
    }

    #[DataProvider('confirmedWrites')]
    #[Requirement('API-2')]
    public function test_every_decision_refuses_without_confirm_true_over_both_transports(string $operation, string $target): void
    {
        [$workspace, $owner, $company, $contact, $agreement] = $this->world('request');
        $request = app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
        $this->actingAsMcp($owner, self::SCOPES);
        [$path, $version, $arguments] = $target === 'request'
            ? [$this->url($workspace, $request->public_id).($operation === 'cancellation_requests.approve' ? '/approve' : '/decline'), AgentApiVersion::for($request), ['cancellation_request_id' => $request->public_id]]
            : ["/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/scheduled-end".($operation === 'agreements.undo_scheduled_end' ? '/undo' : ''), AgentApiVersion::for($agreement->refresh()), ['agreement_id' => $agreement->public_id]];
        $body = ['expected_version' => $version, ...($operation === 'agreements.schedule_end' ? ['end_date' => '2026-12-31'] : [])];

        $this->postJson($path, $body, ['Idempotency-Key' => 'synthetic-unconfirmed'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $result = $this->callTool($this->initialize(), $operation, ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-unconfirmed-mcp', ...$arguments, ...$body, 'confirm' => false]);
        $this->assertTrue(($result['result']['isError'] ?? false) || isset($result['error']), json_encode($result));
        $this->assertSame('pending', $request->refresh()->status);
        $this->assertSame(0, AgreementScheduledTermination::query()->count());
    }

    /** API-5: a client's own contact, a member and another workspace's owner find nothing here. */
    #[Requirement('API-5', 'CAN-4')]
    public function test_only_this_workspaces_owners_and_admins_reach_requests_and_ends(): void
    {
        [$workspace, , $company, $contact, $agreement] = $this->world('request');
        $request = app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
        [, $otherOwner] = $this->packageWorkspace('Synthetic Studio Two');
        $member = $this->workspaceMember($workspace, 'member');

        foreach ([$contact, $member, $otherOwner] as $outsider) {
            $this->actingAsMcp($outsider, self::SCOPES);
            $this->getJson($this->url($workspace))->assertNotFound();
            $this->getJson($this->url($workspace, $request->public_id))->assertNotFound();
            $this->postJson($this->url($workspace, $request->public_id).'/approve', ['expected_version' => AgentApiVersion::for($request), 'confirm' => true], ['Idempotency-Key' => 'synthetic-outsider-'.Str::random(6)])->assertNotFound();
            $this->postJson("/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/scheduled-end", ['expected_version' => AgentApiVersion::for($agreement), 'confirm' => true, 'end_date' => '2026-12-31'], ['Idempotency-Key' => 'synthetic-outsider-'.Str::random(6)])->assertNotFound();
        }
        $this->assertSame('pending', $request->refresh()->status);
    }

    /** A client's contact reads where their service's cancellation stands; acting is the portal's. */
    #[Requirement('API-5', 'CAN-2', 'CAN-3', 'EDGE-9')]
    public function test_a_client_contact_reads_the_cancellation_state_of_their_service(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $package = $this->publishedPackage($workspace, $owner, ['cancellation_mode' => 'self_serve', 'notice_days' => 30, 'invoice_issuing' => 'draft']);
        $company = $this->company($workspace);
        $contact = $this->contact($company);
        $workflow = app(PackageEnrollmentWorkflow::class);
        $enrollment = $workflow->accept($contact, $company, $workflow->addFromPortal($contact, $company, $package), ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true]);
        $this->actingAsMcp($contact, ['mcp:use', 'packages:read']);

        $before = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/client-services/{$enrollment->public_id}")->assertOk();
        $this->schema('client_services.get', $before->json());
        $before->assertJsonPath('data.cancellation', [
            'mode' => 'self_serve', 'notice_days' => 30, 'minimum_term_months' => null, 'earliest_end_on' => '2026-12-31',
            'offered_end_dates' => ['2026-12-31', '2027-01-31', '2027-02-28'], 'pending_request' => null, 'scheduled_end' => null,
        ]);

        $agreement = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        app(AgreementCancellations::class)->cancelByContact($contact, $company, $agreement, '2026-12-31', null);
        $after = $this->callTool($this->initialize(), 'client_services.get', ['workspace_id' => $workspace->public_id, 'service_id' => $enrollment->public_id]);
        $cancellation = $after['result']['structuredContent']['data']['cancellation'];
        $this->assertSame([], $cancellation['offered_end_dates']);
        $this->assertSame(['2026-12-31', null, 'self_serve'], [$cancellation['scheduled_end']['ends_on'], $cancellation['scheduled_end']['previous_ends_on'], $cancellation['scheduled_end']['source']]);
        // Reading is all: the operator operations answer a contact as not found.
        $this->actingAsMcp($contact, self::SCOPES);
        $this->getJson($this->url($workspace))->assertNotFound();
    }

    /** @return array{0: Workspace, 1: User, 2: ClientCompany, 3: User, 4: ClientAgreement} */
    private function world(string $mode): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $company = $this->company($workspace);
        $workflow = app(AgreementWorkflow::class);
        $agreement = $workflow->create($workspace, $company, null, null, ['title' => 'Synthetic hosting', 'starts_on' => '2026-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'is_visible_to_client' => true]);
        $agreement = $workflow->activate($workflow->update($workspace, $agreement, ['notice_days' => 30, 'cancellation_mode' => $mode]))->refresh();

        return [$workspace->refresh(), $owner, $company, $this->contact($company), $agreement];
    }

    private function company(Workspace $workspace): ClientCompany
    {
        return ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => 'lakeside-'.Str::uuid(), 'billing_email' => 'billing-lakeside@example.com']);
    }

    private function contact(ClientCompany $company, string $scope = 'company'): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => $scope]);

        return $user;
    }

    private function url(Workspace $workspace, ?string $request = null): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/cancellation-requests'.($request === null ? '' : '/'.$request);
    }

    /** @param array<string, mixed> $body */
    private function schema(string $operation, array $body): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::forOperation($operation)));
    }
}
