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
];
