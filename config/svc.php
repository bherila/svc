<?php

return [
    'filesystem_disk' => env('SVC_FILESYSTEM_DISK', 'svc_files'),

    // Inviting portal contacts who have never signed in (#430). Off by default:
    // it asks the identity provider to admit the invited address, so the
    // provider must also be configured for this application
    // (`bherila-auth.application_invitations`) and have it enabled there.
    // While off, no invitation is created, listed, resent, revoked or applied
    // at sign-in, and the agent operations are withheld.
    'portal_invitations' => [
        'enabled' => (bool) env('SVC_PORTAL_INVITATIONS_ENABLED', false),
    ],

    // The external finance system that reconciles payments, as SVC shows it.
    // A payment with an active allocation from `external_system` is badged
    // "Reconciled in finance" on its invoice screen, linked to each
    // transaction at `transaction_url`: an absolute http(s) address in which
    // `{transaction_uuid}` is replaced by the finance system's own transaction
    // UUID, exactly as that system first published it. Unset (or not such an
    // address), the badge is shown without a link.
    'finance' => [
        'external_system' => env('SVC_FINANCE_EXTERNAL_SYSTEM', 'finance'),
        'transaction_url' => env('SVC_FINANCE_TRANSACTION_URL'),
    ],
];
