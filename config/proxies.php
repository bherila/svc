<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Production sits behind Cloudflare, and the origin also answers direct
    | connections, so forwarded headers may only be honoured when the connecting
    | address is one of Cloudflare's. Then the client is the address Cloudflare
    | appended to X-Forwarded-For - the rightmost one that is not a proxy - and
    | anything a client wrote further left is ignored. A direct connection's
    | headers are never honoured, so its own address is what rate limits key on.
    |
    | Set TRUSTED_PROXIES to "cloudflare" (the default), to "*" only where an
    | origin firewall admits Cloudflare alone (not true on the shared host), or
    | to a comma-separated list of addresses / CIDR ranges. Empty trusts nothing.
    |
    */

    'trusted' => env('TRUSTED_PROXIES', 'cloudflare'),

    /*
    | Published at https://www.cloudflare.com/ips-v4 and /ips-v6, and checked
    | weekly against them by the cloudflare-ranges workflow. A stale list fails
    | quietly into "callers on a new edge share its address", so refresh it when
    | that workflow fails.
    */
    'cloudflare' => [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ],

];
