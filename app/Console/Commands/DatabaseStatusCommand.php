<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DatabaseStatusCommand extends Command
{
    protected $signature = 'svc:database:status {--format=text : Output text or json}';

    protected $description = 'Verify the production database driver and UUID grammar boundary';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $driver = DB::connection()->getDriverName();
        $serverVersion = null;

        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $serverVersion = (string) DB::selectOne('select version() as version')->version;
            }
        } catch (Throwable) {
            // Deployment output must not echo connection exceptions: they can
            // contain a hostname, database name, or credential-adjacent detail.
        }

        $serverFamily = is_string($serverVersion) && str_contains(strtolower($serverVersion), 'mariadb')
            ? 'mariadb'
            : 'unsupported';
        $compatible = is_string($serverVersion)
            && self::isUuidGrammarCompatible($driver, $serverVersion);
        $status = [
            'driver' => $driver,
            'server_family' => $serverFamily,
            'server_version' => $serverVersion,
            'uuid_grammar_compatible' => $compatible,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode($status, JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Driver', $driver);
            $this->components->twoColumnDetail('Server family', $serverFamily);
            $this->components->twoColumnDetail('Server version', $serverVersion ?? 'unavailable');
            $this->components->twoColumnDetail('UUID grammar compatible', $compatible ? 'yes' : 'no');
        }

        return $compatible ? self::SUCCESS : self::FAILURE;
    }

    /**
     * MariaDBGrammar changes uuid() from char(36) to native uuid at 10.7.
     * Production's existing UUID columns are char(36), so a server upgrade
     * must remain blocked until a deliberate schema migration moves all thirty
     * columns together.
     */
    public static function isUuidGrammarCompatible(string $driver, string $serverVersion): bool
    {
        if ($driver !== 'mariadb' || ! str_contains(strtolower($serverVersion), 'mariadb')) {
            return false;
        }

        $foundVersion = preg_match(
            '/(\d+\.\d+\.\d+)-MariaDB(?:-|$)/i',
            $serverVersion,
            $matches,
        );
        $version = $foundVersion === 1 ? $matches[1] : null;

        return is_string($version) && version_compare($version, '10.7.0', '<');
    }
}
