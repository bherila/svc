<?php

return [
    // Which InvoicePdfRenderer draws the invoice PDF. Dompdf is the only
    // implementation; another engine is added as a new value here and a new
    // class, and is held to the same golden-fact tests.
    'invoice_pdf_renderer' => env('SVC_INVOICE_PDF_RENDERER', 'dompdf'),

    'filesystem_disk' => env('SVC_FILESYSTEM_DISK', 'svc_files'),

    // Keep false for the first reader deployment. After the floor is verified,
    // install the persistent local privacy latch before enabling this check.
    // The latch overrides an old config cache during rollback. Controls are separate.
    'attachments' => [
        'private_uploads_enabled' => (bool) env('SVC_ATTACHMENT_PRIVATE_UPLOADS_ENABLED', false),
        // Independent R2 controls; the retained runtime latch is also required.
        'sharing_writes_enabled' => (bool) env('SVC_ATTACHMENT_SHARING_WRITES_ENABLED', false),
    ],

    // Inviting portal contacts who have never signed in (#430). Off by default:
    // it asks the identity provider to admit the invited address, so the
    // provider must also be configured for this application
    // (`bherila-auth.application_invitations`) and have it enabled there.
    // While off, no invitation is created, listed, resent, revoked or applied
    // at sign-in, and the agent operations are withheld.
    'portal_invitations' => [
        'enabled' => (bool) env('SVC_PORTAL_INVITATIONS_ENABLED', false),
    ],

    // Service packages (docs/service-packages.md). Off by default: while off,
    // no package route, page, navigation entry, agent operation or scheduled
    // command of the feature is reachable (X-1).
    'service_packages' => [
        'enabled' => (bool) env('SVC_SERVICE_PACKAGES_ENABLED', false),
    ],

    // The daily scheduled `svc:finance:sync-processor-movements --apply` runs
    // (#511). Off by default: dispute recovery can change dispute state and
    // invoice receivables, so a deployment previews first and enables the
    // schedule only once those figures are approved. Read when each run is
    // due, so turning it on or off needs a config re-cache, not a deploy.
    // Manual runs of the command are unaffected.
    'processor_movements' => [
        'scheduled_apply' => (bool) env('SVC_PROCESSOR_MOVEMENTS_SCHEDULED_APPLY', false),
    ],

    // The hourly scheduled `svc:billing:generate-cadence-drafts --apply`: a
    // draft for each recurring agreement whose current period has started with
    // no invoice. Drafts only - nothing is issued or sent. Off by default so a
    // deployment previews the command first; read when each run is due, so
    // turning it on or off needs a config re-cache, not a deploy. Manual runs
    // of the command are unaffected.
    'billing' => [
        'scheduled_cadence_drafts' => (bool) env('SVC_BILLING_SCHEDULED_CADENCE_DRAFTS', false),
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

    // Synthetic staging data (Database\Seeders\StagingSeeder). Reserved-domain defaults; name the
    // staging identity provider's test accounts to sign in as them. Never a real client.
    'staging_seed' => [
        'owner_email' => env('SVC_STAGING_SEED_OWNER_EMAIL', 'staging-owner@example.test'),
        'portal_email' => env('SVC_STAGING_SEED_PORTAL_EMAIL', 'staging-portal@example.test'),
    ],

    // Browser security headers (App\Http\Middleware\SecurityHeaders). `csp_mode` is `off`,
    // `report-only` (the default: violations are reported, nothing is blocked) or `enforce`.
    // The retired SVC_CSP_REPORT_ONLY still works when SVC_CSP_MODE is unset: false meant
    // "send no policy", so it maps to `off`; anything else maps to `report-only`.
    // `csp_report_uri` is a collector's https URL or an absolute path; it defaults to SVC's own
    // bounded collector (POST /api/csp-reports), and an empty value sends no reports.
    'security' => [
        // Seconds; 0 turns Strict-Transport-Security off. Never sent over plain HTTP, never preload.
        'hsts_max_age' => (int) env('SVC_HSTS_MAX_AGE', 31536000),
        'csp_mode' => env('SVC_CSP_MODE', env('SVC_CSP_REPORT_ONLY') === false ? 'off' : 'report-only'),
        'csp_report_uri' => env('SVC_CSP_REPORT_URI', '/api/csp-reports'),
    ],

    // Attempts per minute for the named limiters in App\Support\Http\RateLimits.
    // Unset (or not a positive number) keeps the generous default.
    'rate_limits' => [
        'sign-in' => env('SVC_RATE_LIMIT_SIGN_IN'),
        'portal' => env('SVC_RATE_LIMIT_PORTAL'),
        'portal-writes' => env('SVC_RATE_LIMIT_PORTAL_WRITES'),
        'payment-intents' => env('SVC_RATE_LIMIT_PAYMENT_INTENTS'),
        'invitations' => env('SVC_RATE_LIMIT_INVITATIONS'),
        'csp-reports' => env('SVC_RATE_LIMIT_CSP_REPORTS'),
    ],
];
