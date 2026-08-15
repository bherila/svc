<?php

return [
    // SVC uses the shared OAuth client service but does not expose the package's
    // password, passkey, two-factor, or audit-log routes.
    'routes' => [
        'enabled' => false,
    ],
];
