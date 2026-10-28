<?php

namespace App\Console\Commands\Database;

use App\Console\Commands\SvcCommand;
use App\Database\Schema\Grammars\CharacterUuidMariaDbGrammar;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DatabaseStatusCommand extends SvcCommand
{
    protected $signature = 'svc:database:status {--format=text : Output text or json}';

    protected $description = 'Verify the production database driver and UUID grammar boundary';

    public function handle(): int
    {
        $format = (string) $this->option('format');

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
            && self::isUuidGrammarCompatible(
                $driver,
                $serverVersion,
                DB::connection()->getSchemaBuilder()->getConnection()->getSchemaGrammar() instanceof CharacterUuidMariaDbGrammar,
            );
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
     * Existing UUID columns are char(36). A newer server is compatible only
     * while the application's explicit character UUID grammar remains installed.
     */
    public static function isUuidGrammarCompatible(string $driver, string $serverVersion, bool $characterUuidGrammar = false): bool
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

        return is_string($version) && ($characterUuidGrammar || version_compare($version, '10.7.0', '<'));
    }
}
