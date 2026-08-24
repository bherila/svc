<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Explicit source allowlist
    |--------------------------------------------------------------------------
    |
    | A source is intentionally disabled until EXTERNAL_IMPORT_READ_ONLY is
    | explicitly set to true. The connection settings below are independent
    | from config/database.php so this onboarding-import feature does not need
    | a shared database-config edit.
    */
    'sources' => [
        'external' => [
            'connection' => env('EXTERNAL_IMPORT_CONNECTION', 'external'),
            'read_only' => env('EXTERNAL_IMPORT_READ_ONLY', false),
            'config' => [
                'driver' => env('EXTERNAL_IMPORT_DRIVER', 'mysql'),
                'host' => env('EXTERNAL_IMPORT_HOST'),
                'port' => env('EXTERNAL_IMPORT_PORT', '3306'),
                'database' => env('EXTERNAL_IMPORT_DATABASE'),
                'username' => env('EXTERNAL_IMPORT_USERNAME'),
                'password' => env('EXTERNAL_IMPORT_PASSWORD'),
                'unix_socket' => env('EXTERNAL_IMPORT_SOCKET', ''),
                'charset' => env('EXTERNAL_IMPORT_CHARSET', 'utf8mb4'),
                'collation' => env('EXTERNAL_IMPORT_COLLATION', 'utf8mb4_unicode_ci'),
                'prefix' => '',
                'strict' => true,
            ],
        ],
    ],

    'destination_connection' => env('EXTERNAL_IMPORT_DESTINATION_CONNECTION', null),
    'batch_size' => 100,

    // Exact local root of the external source's private blob disk. Attachment
    // keys are resolved beneath this root and may never escape it.
    'attachment_root' => env('EXTERNAL_IMPORT_ATTACHMENT_ROOT'),

    // Never infer identity from email. Values are SVC public UUIDs.
    'user_bindings' => json_decode((string) env('EXTERNAL_IMPORT_USER_BINDINGS', '{}'), true) ?: [],
    'trusted_identity_bindings' => json_decode((string) env('EXTERNAL_IMPORT_TRUSTED_IDENTITIES', '{}'), true) ?: [],
];
