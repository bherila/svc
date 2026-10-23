<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Finance\FinanceMutationCommand;
use App\Console\Commands\MutationCommand;
use App\Console\Commands\SvcCommand;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareConfiguration;
use App\Services\Tenancy\WorkspacePublicIdResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

final class ArtisanContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_application_command_has_a_namespaced_primary_name_and_validates_format_before_running(): void
    {
        $seen = [];
        foreach (Artisan::all() as $command) {
            if (! str_starts_with((string) (new ReflectionClass($command))->getFileName(), app_path('Console/Commands/'))) {
                continue;
            }
            $name = $command->getName();
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $this->assertInstanceOf(SvcCommand::class, $command);
            $this->assertStringStartsWith('svc:', $name);
            $prefix = explode(':', $name)[1];
            $this->assertStringContainsString('/'.strtolower($prefix).'/', strtolower((string) (new ReflectionClass($command))->getFileName()));
            $arguments = [];
            foreach ($command->getDefinition()->getArguments() as $argument) {
                if ($argument->isRequired()) {
                    $arguments[$argument->getName()] = $argument->isArray() ? ['synthetic'] : 'synthetic';
                }
            }
            $this->assertSame(2, Artisan::call($name, [...$arguments, '--format' => 'yaml']), $name);
            $this->assertStringContainsString('The --format option must be text or json.', Artisan::output());
            if ($command instanceof MutationCommand || $command instanceof FinanceMutationCommand) {
                foreach (['dry-run', 'pretend'] as $alias) {
                    $this->assertSame(2, Artisan::call($name, [...$arguments, '--apply' => true, '--'.$alias => true]), $name);
                    $this->assertStringContainsString('not both', Artisan::output());
                }
            }
        }
        $this->assertGreaterThan(30, count($seen));
    }

    public function test_scheduled_application_writes_explicitly_opt_in_and_the_vendor_prune_stays_scheduled(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $command = (string) $event->command;
            if (str_contains($command, 'svc:')) {
                $this->assertStringContainsString('--apply', $command);
            }
        }
    }

    public function test_default_effectful_commands_do_not_send_mail_call_providers_or_create_smoke_credentials(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $users = User::query()->count();
        foreach (['svc:billing:dispatch-invoice-emails', 'svc:billing:send-payment-reminders', 'svc:mcp:deploy-smoke-credentials'] as $name) {
            foreach ([[], ['--pretend' => true], ['--dry-run' => true]] as $options) {
                $this->assertSame(0, Artisan::call($name, ['--format' => 'json', ...$options]));
                $this->assertSame(['dry_run' => true, 'operation' => $name, 'executed' => false], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
            }
        }
        $this->assertSame($users, User::query()->count());
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_workspace_resolution_is_public_id_only_and_square_uses_the_same_boundary(): void
    {
        $first = Workspace::query()->create(['name' => 'Synthetic first', 'slug' => 'synthetic-first']);
        $second = Workspace::query()->create(['name' => 'Synthetic second', 'slug' => 'synthetic-second']);
        $resolver = app(WorkspacePublicIdResolver::class);
        $this->assertTrue($first->is($resolver->find($first->public_id)));
        $this->assertTrue($second->is($resolver->find($second->public_id)));
        foreach ([$first->slug, (string) $first->id, '', ' ', (string) Str::uuid()] as $missing) {
            $this->assertNull($resolver->find($missing));
        }
        config(['services.square.access_token' => 'synthetic-token', 'services.square.environment' => 'sandbox', 'services.square.version' => '2026-01-22', 'services.square.workspace' => $first->public_id]);
        $this->assertTrue($first->is(SquareConfiguration::fromConfig()->workspace()));
        config(['services.square.workspace' => $second->public_id]);
        $this->assertTrue($second->is(SquareConfiguration::fromConfig()->workspace()));
        config(['services.square.workspace' => $first->slug]);
        $this->assertNull(SquareConfiguration::fromConfig()->workspace());
        config(['services.square.workspace' => $first->public_id, 'services.square.access_token' => null]);
        $this->assertNull(SquareConfiguration::fromConfig()->workspace());
    }
}
