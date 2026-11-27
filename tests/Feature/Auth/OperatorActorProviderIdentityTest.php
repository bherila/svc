<?php

namespace Tests\Feature\Auth;

use App\Console\Commands\Concerns\FindsUserByPublicId;
use App\Console\Commands\Engagement\LogTimeEntryCommand;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Auth\OperatorActorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesProviderIdentity;
use Tests\TestCase;

/**
 * Operator commands that act as a named account (docs/provider-identity.md, gap 1). Under
 * provider identity enforcement, a command refuses to act as a bound account whose identity
 * has ended, and refuses retryably while the provider cannot say; either way nothing is
 * written. Unbound accounts, and every account while enforcement is off, are unchanged.
 *
 * One representative command per way a command names the account: `svc:tasks` through
 * {@see FindsUserByPublicId} (as every `--actor`/`--user` command does but log-time), and
 * `svc:engagement:log-time` through {@see LogTimeEntryCommand}'s worker lookup.
 */
#[CoversClass(OperatorActorIdentity::class)]
#[CoversClass(LogTimeEntryCommand::class)]
final class OperatorActorProviderIdentityTest extends TestCase
{
    use FakesProviderIdentity;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientProject $project;

    private ?string $input = null;

    protected function tearDown(): void
    {
        if ($this->input !== null && is_file($this->input)) {
            unlink($this->input);
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function commands(): iterable
    {
        yield 'svc:tasks (FindsUserByPublicId)' => ['tasks'];
        yield 'svc:engagement:log-time (resolveWorker)' => ['log-time'];
    }

    #[DataProvider('commands')]
    public function test_an_ended_identity_is_refused_and_nothing_is_written(string $command): void
    {
        $this->fakeProviderIdentity();
        $actor = $this->member($this->boundUser('operator-actor-ended'));
        $this->disableProviderIdentity('operator-actor-ended');

        [$exit, $output] = $this->runAs($command, $actor);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString(OperatorActorIdentity::ENDED, $output);
        $this->assertNothingWritten();
        $this->assertSame(1, $this->providerStatusRequests);
    }

    #[DataProvider('commands')]
    public function test_an_active_identity_acts_after_asking_the_provider(string $command): void
    {
        $this->fakeProviderIdentity();
        $actor = $this->member($this->boundUser('operator-actor-active'));
        $this->providerIdentity('operator-actor-active', 4);

        [$exit, $output] = $this->runAs($command, $actor);

        $this->assertSame(0, $exit, $output);
        $this->assertWritten($command);
        $this->assertSame(1, $this->providerStatusRequests);
    }

    #[DataProvider('commands')]
    public function test_an_unavailable_provider_is_a_retryable_refusal_that_writes_nothing(string $command): void
    {
        $this->fakeProviderIdentity();
        $actor = $this->member($this->boundUser('operator-actor-outage'));
        $this->providerIdentity('operator-actor-outage', 4);
        $this->takeProviderOffline();

        [$exit, $output] = $this->runAs($command, $actor);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString(OperatorActorIdentity::UNAVAILABLE, $output);
        $this->assertNothingWritten();

        // The same command succeeds once the provider answers again.
        $this->takeProviderOffline(false);
        [$retry, $retried] = $this->runAs($command, $actor);
        $this->assertSame(0, $retry, $retried);
        $this->assertWritten($command);
    }

    #[DataProvider('commands')]
    public function test_an_unbound_account_is_never_checked(string $command): void
    {
        $this->fakeProviderIdentity();
        $actor = $this->member(User::factory()->create());

        [$exit, $output] = $this->runAs($command, $actor);

        $this->assertSame(0, $exit, $output);
        $this->assertWritten($command);
        $this->assertSame(0, $this->providerStatusRequests);
    }

    #[DataProvider('commands')]
    public function test_nothing_changes_while_enforcement_is_off(string $command): void
    {
        $this->fakeProviderIdentity(enforced: false);
        $actor = $this->member($this->boundUser('operator-actor-unenforced'));
        $this->disableProviderIdentity('operator-actor-unenforced');

        [$exit, $output] = $this->runAs($command, $actor);

        $this->assertSame(0, $exit, $output);
        $this->assertWritten($command);
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_a_broken_binding_is_refused_rather_than_treated_as_unbound(): void
    {
        $this->fakeProviderIdentity();
        $actor = $this->boundUser('operator-actor-broken');
        $actor->forceFill(['oauth_provider' => 'another-provider'])->save();
        $this->member($actor);

        [$exit, $output] = $this->runAs('tasks', $actor);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString(OperatorActorIdentity::BROKEN_BINDING, $output);
        $this->assertNothingWritten();
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_the_covered_square_payments_command_shares_the_check(): void
    {
        $this->fakeProviderIdentity();
        $actor = $this->member($this->boundUser('operator-actor-square'));
        $this->disableProviderIdentity('operator-actor-square');

        $exit = Artisan::call('svc:square:covered-payments', [
            'workspace' => $this->workspace->public_id,
            'invoice' => '00000000-0000-4000-8000-000000000001',
            'square_invoice' => 'synthetic-square-invoice',
            '--actor' => $actor->public_id,
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString(OperatorActorIdentity::ENDED, Artisan::output());
        $this->assertSame(1, $this->providerStatusRequests);
    }

    public function test_revoking_an_ended_identity_s_credential_still_works_and_never_asks(): void
    {
        $this->fakeProviderIdentity();
        $owner = $this->boundUser('operator-actor-revoked');
        $owner->createToken('synthetic-finance', ['finance.read'], now()->addHour());
        $this->disableProviderIdentity('operator-actor-revoked');
        $this->takeProviderOffline();

        $exit = Artisan::call('svc:auth:revoke-token', [
            'user_public_id' => $owner->public_id,
            'name' => 'synthetic-finance',
            '--apply' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertSame(0, $this->providerStatusRequests);
    }

    /** A workspace owner who manages the project: may create tasks and log time there. */
    private function member(User $user): User
    {
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic operator actor workspace', 'slug' => 'synthetic-operator-actor']);
        $this->workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic operator client', 'slug' => 'synthetic-operator-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic operator project']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id, 'user_id' => $user->id, 'role' => 'manager',
        ]);

        return $user;
    }

    /** @return array{int, string} the exit code and the output */
    private function runAs(string $command, User $actor): array
    {
        $exit = match ($command) {
            'tasks' => Artisan::call('svc:tasks', [
                'operation' => 'create',
                'workspace' => $this->workspace->public_id,
                '--actor' => $actor->public_id,
                '--project' => $this->project->public_id,
                '--input' => $this->taskInput(),
                '--key' => 'synthetic-operator-actor-create',
                '--apply' => true,
                '--format' => 'json',
            ]),
            'log-time' => Artisan::call('svc:engagement:log-time', [
                'workspace' => $this->workspace->public_id,
                'project' => $this->project->public_id,
                'minutes' => '30',
                'description' => 'Synthetic operator work',
                '--user' => (string) $actor->id,
                '--apply' => true,
                '--format' => 'json',
            ]),
        };

        return [$exit, Artisan::output()];
    }

    private function taskInput(): string
    {
        if ($this->input === null) {
            $this->input = (string) tempnam(sys_get_temp_dir(), 'svc-operator-actor-');
            file_put_contents($this->input, json_encode(['title' => 'Synthetic operator task'], JSON_THROW_ON_ERROR));
        }

        return $this->input;
    }

    private function assertNothingWritten(): void
    {
        $this->assertDatabaseCount('client_tasks', 0);
        $this->assertDatabaseCount('client_time_entries', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('agent_mutation_audits', 0);
    }

    private function assertWritten(string $command): void
    {
        match ($command) {
            'tasks' => $this->assertDatabaseCount('client_tasks', 1),
            'log-time' => $this->assertDatabaseCount('client_time_entries', 1),
        };
    }
}
