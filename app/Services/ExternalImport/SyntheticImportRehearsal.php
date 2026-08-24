<?php

namespace App\Services\ExternalImport;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class SyntheticImportRehearsal
{
    /** @var list<string> */
    private const CANONICAL_TABLES = [
        'users',
        'workspaces',
        'client_companies',
        'client_company_memberships',
        'client_projects',
        'client_tasks',
    ];

    public function __construct(
        private readonly SyntheticExternalSource $sourceFixture,
        private readonly ExternalImportService $import,
        private readonly Migrator $migrator,
    ) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new SyntheticImportRehearsalException('non_disposable_environment');
        }

        $suffix = str_replace('-', '', (string) Str::uuid());
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'svc-external-import-rehearsal-'.$suffix;
        $sourcePath = $directory.DIRECTORY_SEPARATOR.'source.sqlite';
        $destinationPath = $directory.DIRECTORY_SEPARATOR.'destination.sqlite';
        $sourceName = 'synthetic_rehearsal_'.$suffix;
        $destinationName = 'svc_rehearsal_'.$suffix;
        $sourceRuntimeName = null;

        $originalDestination = Config::get('external-import.destination_connection');
        $originalBindings = Config::get('external-import.user_bindings');
        $originalSource = Config::get("external-import.sources.{$sourceName}");
        $originalConnection = Config::get("database.connections.{$destinationName}");

        $this->createDirectory($directory);

        try {
            $fixture = $this->sourceFixture->create($sourcePath);
            $this->createEmptyFile($destinationPath);
            $this->assertDisposablePaths($directory, $sourcePath, $destinationPath);

            Config::set("database.connections.{$destinationName}", [
                'driver' => 'sqlite',
                'database' => $destinationPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
            Config::set('external-import.destination_connection', $destinationName);
            Config::set("external-import.sources.{$sourceName}", [
                'connection' => $sourceName,
                'read_only' => true,
                'config' => [
                    'driver' => 'sqlite',
                    'database' => $sourcePath,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
            ]);

            DB::purge($destinationName);
            $this->migrator->usingConnection($destinationName, function (): void {
                if (! $this->migrator->repositoryExists()) {
                    $this->migrator->getRepository()->createRepository();
                }

                $this->migrator->run(database_path('migrations'));
            });

            [$workspace, $user] = $this->seedDestination($destinationName);
            Config::set('external-import.user_bindings', [
                (string) $fixture['external_user_id'] => $user->public_id,
            ]);

            $source = app(SourceGuard::class)->resolve($sourceName);
            $sourceRuntimeName = app(SourceGuard::class)->runtimeName($source);

            $first = $this->import->run($sourceName, $workspace->public_id, true);
            $firstVerification = $this->import->verify((string) $first['run_public_id'], $workspace->public_id);
            $firstState = $this->canonicalState($destinationName);

            $second = $this->import->run($sourceName, $workspace->public_id, true);
            $secondVerification = $this->import->verify((string) $second['run_public_id'], $workspace->public_id);
            $secondState = $this->canonicalState($destinationName);

            $checks = [
                'distinct_databases' => realpath($sourcePath) !== realpath($destinationPath),
                'source_count_stable' => ($first['counts']['source_rows'] ?? null) === ($second['counts']['source_rows'] ?? null)
                    && ($second['counts']['source_rows'] ?? null) === $fixture['source_rows'],
                'source_fingerprints_stable' => ($first['fingerprints'] ?? null) === ($second['fingerprints'] ?? null),
                'first_verification_passed' => $firstVerification['ok'] === true,
                'second_verification_passed' => $secondVerification['ok'] === true,
                'second_run_idempotent' => ($second['counts']['imported'] ?? -1) === 0
                    && ($second['counts']['idempotent'] ?? 0) === $fixture['source_rows'],
                'canonical_counts_stable' => $firstState['counts'] === $secondState['counts'],
                'canonical_fingerprint_stable' => $firstState['fingerprint'] === $secondState['fingerprint'],
            ];

            if (in_array(false, $checks, true)) {
                throw new SyntheticImportRehearsalException('rehearsal_checks_failed');
            }

            return [
                'ok' => true,
                'mode' => 'synthetic-disposable',
                'redacted' => true,
                'source_rows' => $fixture['source_rows'],
                'destination_counts' => $secondState['counts'],
                'canonical_fingerprint' => $secondState['fingerprint'],
                'first_run' => $this->runSummary($first),
                'second_run' => $this->runSummary($second),
                'checks' => $checks,
                'artifacts' => 'removed',
            ];
        } finally {
            if (is_string($sourceRuntimeName)) {
                DB::purge($sourceRuntimeName);
            }
            DB::purge($destinationName);
            Config::set('external-import.destination_connection', $originalDestination);
            Config::set('external-import.user_bindings', $originalBindings);
            Config::set("external-import.sources.{$sourceName}", $originalSource);
            Config::set("database.connections.{$destinationName}", $originalConnection);
            $this->removeArtifacts($directory, [$sourcePath, $destinationPath]);
        }
    }

    private function createDirectory(string $directory): void
    {
        if (file_exists($directory) || ! mkdir($directory, 0700)) {
            throw new SyntheticImportRehearsalException('temporary_directory_unavailable');
        }
    }

    private function createEmptyFile(string $path): void
    {
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new SyntheticImportRehearsalException('temporary_database_unavailable');
        }

        fclose($handle);
    }

    private function assertDisposablePaths(string $directory, string $sourcePath, string $destinationPath): void
    {
        $resolvedDirectory = realpath($directory);
        $expectedPrefix = 'svc-external-import-rehearsal-';
        if ($resolvedDirectory === false
            || realpath(dirname($sourcePath)) !== $resolvedDirectory
            || realpath(dirname($destinationPath)) !== $resolvedDirectory
            || ! str_starts_with(basename($resolvedDirectory), $expectedPrefix)
            || realpath($sourcePath) === realpath($destinationPath)) {
            throw new SyntheticImportRehearsalException('non_disposable_path_refused');
        }
    }

    /** @return array{Workspace, User} */
    private function seedDestination(string $connection): array
    {
        $user = (new User)->setConnection($connection);
        $user->forceFill([
            'name' => 'Synthetic Import Operator',
            'email' => 'import-operator@synthetic.test',
            'password' => 'synthetic-unusable-password',
        ])->save();

        $workspace = (new Workspace)->setConnection($connection);
        $workspace->fill([
            'name' => 'Synthetic Rehearsal Workspace',
            'slug' => 'synthetic-rehearsal',
        ])->save();

        return [$workspace, $user];
    }

    /** @return array{counts: array<string, int>, fingerprint: string} */
    private function canonicalState(string $connection): array
    {
        $counts = [];
        $rows = [];

        foreach (self::CANONICAL_TABLES as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                throw new SyntheticImportRehearsalException('canonical_table_missing');
            }

            $tableRows = DB::connection($connection)->table($table)->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
            $counts[$table] = count($tableRows);
            foreach ($tableRows as $row) {
                $rows[] = ['table' => $table, 'row' => $row];
            }
        }

        return ['counts' => $counts, 'fingerprint' => Fingerprint::rows($rows)];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function runSummary(array $summary): array
    {
        return [
            'run_public_id' => $summary['run_public_id'],
            'status' => $summary['status'],
            'counts' => $summary['counts'],
            'fingerprints' => $summary['fingerprints'],
        ];
    }

    /** @param list<string> $paths */
    private function removeArtifacts(string $directory, array $paths): void
    {
        foreach ($paths as $path) {
            foreach ([$path, $path.'-shm', $path.'-wal', $path.'-journal'] as $artifact) {
                if (is_file($artifact) && ! is_link($artifact) && ! unlink($artifact)) {
                    throw new SyntheticImportRehearsalException('temporary_cleanup_failed');
                }
            }
        }

        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory !== false
            && str_starts_with(basename($resolvedDirectory), 'svc-external-import-rehearsal-')
            && realpath(dirname($paths[0])) === $resolvedDirectory) {
            if (! rmdir($resolvedDirectory)) {
                throw new SyntheticImportRehearsalException('temporary_cleanup_failed');
            }
        }

        if (file_exists($directory)) {
            throw new SyntheticImportRehearsalException('temporary_cleanup_failed');
        }
    }
}
