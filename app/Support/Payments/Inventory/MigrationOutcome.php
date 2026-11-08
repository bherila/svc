<?php

namespace App\Support\Payments\Inventory;

/** What the command would do for one processor. The values are the command's printed statuses. */
enum MigrationOutcome: string
{
    case NoEnvironmentCredentials = 'no_environment_credentials';
    case AlreadyMigrated = 'already_migrated';
    case WorkspaceAlreadyConnected = 'workspace_already_connected';
    case OwnershipAmbiguous = 'ownership_ambiguous';
    case NeedsWorkspace = 'needs_workspace';
    case WouldMigrate = 'would_migrate';

    /** Whether a run that ends here is not a clean one. */
    public function failsRun(): bool
    {
        return match ($this) {
            self::WorkspaceAlreadyConnected, self::OwnershipAmbiguous, self::NeedsWorkspace => true,
            self::NoEnvironmentCredentials, self::AlreadyMigrated, self::WouldMigrate => false,
        };
    }

    public function sentence(): string
    {
        return match ($this) {
            self::NoEnvironmentCredentials => 'Nothing to migrate: the environment has no credentials for this processor.',
            self::AlreadyMigrated => 'Nothing to do: the environment credentials were already turned into a connection.',
            self::WorkspaceAlreadyConnected => 'Skipped: the owning workspace already has an active connection, and the command never replaces one.',
            self::OwnershipAmbiguous => 'Blocked: more than one workspace could own this connection. Re-run with --workspace=<public id>.',
            self::NeedsWorkspace => 'Blocked: no workspace was named. Re-run with --workspace=<public id>.',
            self::WouldMigrate => 'Would migrate: --apply would store the environment credentials as the owner workspace\'s connection.',
        };
    }
}
