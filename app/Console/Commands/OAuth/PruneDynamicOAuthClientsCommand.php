<?php

namespace App\Console\Commands\OAuth;

use App\Console\Commands\MutationCommand;
use BWH\Auth\Console\PruneDynamicClientsCommand;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The old name for {@see PruneDynamicClientsCommand}, kept so a runbook or
 * crontab that names it keeps working (#460).
 *
 * The prune itself is the vendor's `bherila-auth:prune-dynamic-clients`, which
 * the scheduler runs. This command used to be a copy of it that had fallen
 * behind: no lock on the client row while deleting and no check for a flow in
 * progress. It now forwards its options and returns the vendor's exit code, so
 * there is one prune and one retention setting
 * (`bherila-auth.oauth_server.dynamic_clients.retention_days`, still read from
 * `AGENT_API_DYNAMIC_CLIENT_RETENTION_DAYS`).
 */
final class PruneDynamicOAuthClientsCommand extends MutationCommand
{
    public const VENDOR_COMMAND = 'bherila-auth:prune-dynamic-clients';

    protected $signature = 'svc:oauth:prune-dynamic-clients {--days= : Override the configured retention window} {--pretend : Deprecated alias for the default preview}';

    protected $description = 'Alias of bherila-auth:prune-dynamic-clients: delete stale, unused dynamically registered OAuth clients';

    public function handle(): int
    {
        $options = [];
        if ($this->option('days') !== null) {
            $options['--days'] = $this->option('days');
        }
        if (! $this->applying()) {
            $options['--pretend'] = true;
        }

        if ($this->option('format') === 'json') {
            $buffer = new BufferedOutput;
            $exit = $this->runCommand(self::VENDOR_COMMAND, $options, $buffer);
            $this->json(['dry_run' => ! $this->applying(), 'exit_code' => $exit, 'report' => trim($buffer->fetch())]);

            return $exit;
        }

        return $this->call(self::VENDOR_COMMAND, $options);
    }
}
