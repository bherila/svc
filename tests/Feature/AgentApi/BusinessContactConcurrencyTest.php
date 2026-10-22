<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentApi\AgentClientContactMutationAction;
use App\Services\Clients\ContactMutationOrigin;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class BusinessContactConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    public static function operations(): iterable
    {
        yield 'create after demotion' => ['create', 'demote'];
        yield 'update after demotion' => ['update', 'demote'];
        yield 'replay after demotion' => ['replay', 'demote'];
        yield 'update after portal grant' => ['update', 'portal_existing_company'];
        yield 'create after new-company portal grant' => ['create', 'portal_new_company'];
        yield 'replay after new-company portal grant' => ['replay', 'portal_new_company'];
    }

    #[DataProvider('operations')]
    public function test_committed_role_revocation_overrides_an_old_repeatable_read_snapshot(string $operation, string $change): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The authority snapshot regression requires MariaDB.');
        }
        $this->bootProbeDatabase('contact_race');
        Artisan::call('migrate', ['--database' => 'contact_race', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'contact_race']);
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
            $workspace = Workspace::query()->create(['name' => 'Synthetic contact race', 'slug' => 'synthetic-contact-race']);
            $actor = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-company']);
            $writes = app(AgentClientContactMutationAction::class);
            $contactId = $writes->perform($actor, $workspace, $company->public_id, 'create', null, ['first_name' => 'Synthetic'], 'synthetic-contact-race', 'original-create', ContactMutationOrigin::Agent);
            $contact = ClientContact::query()->where('workspace_id', $workspace->id)->where('public_id', $contactId)->sole();
            $refusal = null;
            DB::transaction(function () use ($operation, $change, $workspace, $actor, $company, $writes, $contact, &$refusal): void {
                $this->assertSame('owner', WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->sole()->role);
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->count());
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Clients/contact-authority-race-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode([
                        'connection' => config('database.connections.contact_race'), 'workspace' => $workspace->id, 'actor' => $actor->id, 'company' => $company->id, 'change' => $change,
                    ], JSON_THROW_ON_ERROR)."\n", 20);
                $worker->mustRun();
                $this->assertSame("committed\n", $worker->getOutput());
                $this->assertSame('owner', WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->sole()->role, 'The transaction must retain the old ordinary-read snapshot.');
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->count(), 'The ordinary snapshot must not see the committed portal identity.');
                try {
                    $writes->perform($actor, $workspace, $company->public_id, $operation === 'update' ? 'update' : 'create',
                        $operation === 'update' ? $contact->public_id : null,
                        $operation === 'update' ? ['expected_version' => AgentApiVersion::for($contact), 'phone' => '555-0199'] : ['first_name' => 'Synthetic'],
                        'synthetic-contact-race', $operation === 'replay' ? 'original-create' : 'revoked-'.$operation, ContactMutationOrigin::Agent);
                } catch (HttpExceptionInterface $exception) {
                    $refusal = $exception->getStatusCode();
                }
            });
            $this->assertSame(403, $refusal);
            DB::purge('contact_race');
            $this->assertSame($change === 'demote' ? 'member' : 'owner', WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->sole()->role);
            $this->assertSame($change === 'demote' ? 0 : 1, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->count());
            $this->assertSame(1, ClientContact::query()->where('workspace_id', $workspace->id)->count());
            $this->assertNull(ClientContact::query()->where('workspace_id', $workspace->id)->where('public_id', $contactId)->sole()->phone);
        } finally {
            config(['database.default' => $original]);
        }
    }
}
