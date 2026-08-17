<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Explicit source allowlist
    |--------------------------------------------------------------------------
    |
    | A source is intentionally disabled until LEGACY_MIGRATION_READ_ONLY is
    | explicitly set to true. The connection settings below are independent
    | from config/database.php so this extraction does not need a shared
    | database-config edit.
    */
    'sources' => [
        'legacy' => [
            'connection' => env('LEGACY_MIGRATION_CONNECTION', 'legacy'),
            'read_only' => env('LEGACY_MIGRATION_READ_ONLY', false),
            'config' => [
                'driver' => env('LEGACY_MIGRATION_DRIVER', 'mysql'),
                'host' => env('LEGACY_MIGRATION_HOST'),
                'port' => env('LEGACY_MIGRATION_PORT', '3306'),
                'database' => env('LEGACY_MIGRATION_DATABASE'),
                'username' => env('LEGACY_MIGRATION_USERNAME'),
                'password' => env('LEGACY_MIGRATION_PASSWORD'),
                'unix_socket' => env('LEGACY_MIGRATION_SOCKET', ''),
                'charset' => env('LEGACY_MIGRATION_CHARSET', 'utf8mb4'),
                'collation' => env('LEGACY_MIGRATION_COLLATION', 'utf8mb4_unicode_ci'),
                'prefix' => '',
                'strict' => true,
            ],
        ],
    ],

    'destination_connection' => env('LEGACY_MIGRATION_DESTINATION_CONNECTION', null),
    'batch_size' => 100,

    // Exact local root of the legacy application's private blob disk. Legacy
    // attachment keys are resolved beneath this root and may never escape it.
    'attachment_root' => env('LEGACY_MIGRATION_ATTACHMENT_ROOT'),

    // Never infer identity from email. Values are SVC public UUIDs.
    'user_bindings' => json_decode((string) env('LEGACY_MIGRATION_USER_BINDINGS', '{}'), true) ?: [],
    'trusted_identity_bindings' => json_decode((string) env('LEGACY_MIGRATION_TRUSTED_IDENTITIES', '{}'), true) ?: [],
];
