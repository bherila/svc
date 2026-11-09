import type {
    ClientTerms,
    PackageComponentTerms,
    PackageEditProps,
    PackageListItem,
    PackageMoveCandidate,
    PackageMovePreview,
    PackageMoveProps,
    PackageMoveRow,
    PackageRequestRow,
    PackageRequestsProps,
    PackageTerms,
    PackageVersion,
    PortalService,
    PortalServiceMove,
    PortalServiceProps,
    PortalServicesProps,
    PublicRequestForm,
    ServicePackage,
} from '@/types/packages';

/** Synthetic package terms shaped like the server's presenter. */
export function packageTerms(
    overrides: Partial<PackageTerms> = {},
): PackageTerms {
    const component: PackageComponentTerms = {
        kind: 'recurring',
        description: 'Managed hosting',
        cadence: 'monthly',
        amount: 4500,
        quantity: '1.000',
        is_taxable: false,
    };

    return {
        name: 'Synthetic Hosting',
        summary: 'Managed hosting for synthetic sites.',
        body_markdown: '- SSL and backups\n- Email support',
        agreement_text: null,
        currency: 'USD',
        billing_cadence: 'monthly',
        hourly_rate_amount: null,
        retainer_amount: null,
        retainer_minutes: null,
        rollover_months: null,
        first_cycle_proration: null,
        charge_setup_fee_at_signup: false,
        charge_first_period: false,
        require_autopay: false,
        autopay_limit_amount: null,
        pay_before_activation: false,
        payment_lapse_days: 14,
        activation_mode: 'review',
        invoice_issuing: 'draft',
        notice_days: 30,
        minimum_term_months: null,
        cancellation_mode: 'request',
        components: [component],
        intake_questions: [
            {
                key: 'domain',
                label: 'Website address',
                kind: 'short_text',
                required: true,
            },
        ],
        ...overrides,
    };
}

export function packageVersion(
    number: number,
    terms: PackageTerms = packageTerms(),
    published = false,
): PackageVersion {
    return {
        id: `version-${number}`,
        number,
        published_at: published ? '2026-10-02T12:00:00Z' : null,
        terms,
        setup_fee_total: 0,
        cycle_amount: 4500,
    };
}

export function servicePackage(
    overrides: Partial<ServicePackage> = {},
): ServicePackage {
    return {
        id: 'package-1',
        link_name: 'synthetic-hosting',
        status: 'draft',
        is_listed: true,
        portal_addable: false,
        public_url: null,
        published: null,
        draft: packageVersion(1),
        publish_refusals: [],
        version: 'a'.repeat(64),
        created_at: '2026-10-01T12:00:00Z',
        updated_at: '2026-10-01T12:00:00Z',
        ...overrides,
    };
}

export function listItem(
    overrides: Partial<PackageListItem> = {},
): PackageListItem {
    return {
        ...servicePackage(),
        edit_url: '/workspaces/workspace-1/packages/package-1',
        ...overrides,
    };
}

export function editProps(
    overrides: Partial<PackageEditProps> = {},
): PackageEditProps {
    return {
        package: servicePackage(),
        defaults: null,
        options: {
            billing_cadences: [
                'monthly',
                'quarterly',
                'semi_annual',
                'annual',
                'one_time',
            ],
            component_kinds: ['recurring', 'setup_fee'],
            activation_modes: ['automatic', 'review'],
            invoice_issuing: ['automatic', 'draft'],
            cancellation_modes: ['none', 'request', 'self_serve'],
            question_kinds: ['short_text', 'paragraph'],
        },
        urls: {
            index: '/workspaces/workspace-1/packages',
            store: null,
            update: '/api/v1/workspaces/ws-1/packages/package-1',
            publish: '/api/v1/workspaces/ws-1/packages/package-1/publish',
            retire: null,
        },
        catalog_url: null,
        stripe_connected: true,
        ...overrides,
    };
}

/** A synthetic, still-open sign-up request, shaped like the server's presenter. */
export function requestRow(
    overrides: Partial<PackageRequestRow> = {},
): PackageRequestRow {
    return {
        id: 'request-1',
        status: 'new',
        package: {
            id: 'package-1',
            name: 'Synthetic Hosting',
            link_name: 'synthetic-hosting',
            version_number: 2,
        },
        submitted_at: '2026-11-09T09:14:00Z',
        decided_at: null,
        decline_reason: null,
        client: null,
        suggested_client: null,
        erased_at: null,
        untrusted: {
            company_name: 'Lakeside Bakery',
            contact_name: 'Morgan Ortiz',
            email: 'morgan@lakeside.example.test',
            phone: null,
            answers: [
                {
                    key: 'domain',
                    question: 'Website address',
                    answer: 'lakeside.example.test',
                },
            ],
        },
        version: 'b'.repeat(64),
        urls: {
            approve:
                '/api/v1/workspaces/ws-1/package-requests/request-1/approve',
            decline:
                '/api/v1/workspaces/ws-1/package-requests/request-1/decline',
            erase: null,
        },
        ...overrides,
    };
}

export function requestsProps(
    overrides: Partial<PackageRequestsProps> = {},
): PackageRequestsProps {
    return {
        requests: [requestRow()],
        next_page_url: null,
        first_page_url: null,
        clients: [
            { id: 'client-1', name: 'Pine Street Dental' },
            { id: 'client-2', name: 'Harbor Yoga' },
        ],
        catalog_url: null,
        stripe_connected: true,
        requests_url: '/workspaces/workspace-1/packages/requests',
        open_requests_count: 1,
        ...overrides,
    };
}

export function requestForm(
    overrides: Partial<PublicRequestForm> = {},
): PublicRequestForm {
    return {
        submit_url: '/s/tok123/synthetic-hosting/request',
        form_token: 'opaque-form-token',
        honeypot_field: 'website_url',
        questions: [
            {
                key: 'domain',
                label: 'Website address',
                kind: 'short_text',
                required: true,
                max_length: 255,
            },
            {
                key: 'goal',
                label: 'What should the first hours go toward?',
                kind: 'paragraph',
                required: false,
                max_length: 2000,
            },
        ],
        prefill: null,
        ...overrides,
    };
}

/** Synthetic client-facing terms shaped like PackageEnrollmentPresenter::clientTerms(). */
export function clientTerms(overrides: Partial<ClientTerms> = {}): ClientTerms {
    return {
        name: 'Synthetic Care plan',
        summary: 'Hosting and care for synthetic sites.',
        body_markdown: '- SSL and backups',
        agreement_text:
            'This agreement covers the synthetic care plan described below.',
        currency: 'USD',
        billing_cadence: 'monthly',
        cycle_amount: 64500,
        setup_fee_amount: null,
        components: [
            {
                kind: 'recurring',
                description: 'Managed hosting',
                cadence: 'monthly',
                amount: 4500,
            },
        ],
        hourly_rate_amount: 14000,
        retainer_amount: 60000,
        retainer_minutes: 300,
        rollover_months: 2,
        activation_mode: 'review',
        notice_days: 30,
        minimum_term_months: 6,
        cancellation_mode: 'request',
        ...overrides,
    };
}

export function portalService(
    overrides: Partial<PortalService> = {},
): PortalService {
    return {
        id: 'enrollment-1',
        status: 'offered',
        company: { id: 'company-1', name: 'Synthetic Client' },
        project_id: 'project-1',
        name: 'Synthetic Care plan',
        version_number: 2,
        version_changed: false,
        terms: clientTerms(),
        acceptance: null,
        activated_at: null,
        agreement_url: null,
        payment: {
            state: 'not_charged',
            waiting_for: [],
            signup_invoice: null,
            requires_autopay: false,
            autopay_limit_amount: null,
            pay_before_activation: false,
            lapses_on: '2026-11-23',
        },
        url: '/portal/company-1/services/enrollment-1',
        ...overrides,
    };
}

export function portalServiceProps(
    overrides: Partial<PortalServiceProps> = {},
): PortalServiceProps {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        service: portalService(),
        offered_terms: null,
        changed_terms: [],
        accept_url: '/portal/company-1/services/enrollment-1/accept',
        accept_fields: { reviewed_version: 'version-2' },
        accept_refusal: null,
        checkout: null,
        cancellation: null,
        ...overrides,
    };
}

export function portalServicesProps(
    overrides: Partial<PortalServicesProps> = {},
): PortalServicesProps {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        services: [portalService()],
        addable_packages: [
            {
                id: 'package-1',
                name: 'Synthetic Hosting',
                summary: 'Managed hosting for synthetic sites.',
                currency: 'USD',
                billing_cadence: 'monthly',
                cycle_amount: 4500,
                setup_fee_amount: 15000,
                minimum_term_months: null,
                url: 'https://example.test/p/synthetic-hosting',
                add_refusal: null,
                add_url: '/portal/company-1/services',
            },
        ],
        ...overrides,
    };
}

/** A scheduled move as the client is told of it (MIG-2), shaped like PackageMigrationPresenter::forClient(). */
export function portalServiceMove(
    overrides: Partial<PortalServiceMove> = {},
): PortalServiceMove {
    return {
        effective_on: '2027-01-01',
        version_number: 3,
        changes: [
            {
                term: 'cycle_amount',
                label: 'Price',
                from: '$645.00 a month',
                to: '$695.00 a month',
            },
            {
                term: 'retainer_amount',
                label: 'Retainer',
                from: '$600.00 a month',
                to: '$650.00 a month',
            },
        ],
        terms: clientTerms({ cycle_amount: 69500, retainer_amount: 65000 }),
        message: 'Our hosting provider raised its prices this year.',
        new_price_amount: 69500,
        autopay_limit_amount: null,
        autopay_below_price: false,
        ...overrides,
    };
}

/** One subscriber of a move preview (package_migrations.preview). */
export function moveCandidate(
    overrides: Partial<PackageMoveCandidate> = {},
): PackageMoveCandidate {
    return {
        enrollment_id: 'enrollment-1',
        client: { id: 'company-1', name: 'Lakeside Synthetic Bakery' },
        agreement: {
            id: 'agreement-1',
            title: 'Synthetic Care plan',
            starts_on: '2026-04-01',
            ends_on: null,
        },
        earliest_effective_on: '2027-01-01',
        effective_dates: ['2027-01-01', '2027-02-01'],
        autopay_limit_amount: null,
        autopay_below_price: false,
        pending_cancellation_end_on: null,
        scheduled_end_on: null,
        refusal: null,
        ...overrides,
    };
}

export function moveProps(
    overrides: Partial<PackageMoveProps> = {},
    preview: Partial<PackageMovePreview> = {},
): PackageMoveProps {
    return {
        preview: {
            package: {
                id: 'package-1',
                link_name: 'synthetic-care',
                name: 'Synthetic Care plan',
            },
            from_version: { id: 'version-2', number: 2 },
            to_version: { id: 'version-3', number: 3 },
            refusal: null,
            changes: portalServiceMove().changes,
            new_price: { amount: 69500, currency: 'USD', label: '$695.00' },
            effective_dates: [
                { date: '2027-01-01', subscribers: 2 },
                { date: '2027-02-01', subscribers: 3 },
            ],
            effective_on: '2027-01-01',
            subscribers: [moveCandidate()],
            ...preview,
        },
        package_version: 'a'.repeat(64),
        urls: {
            schedule:
                '/api/v1/workspaces/workspace-1/packages/package-1/migrations',
            editor: '/workspaces/workspace-1/packages/package-1',
            preview:
                '/workspaces/workspace-1/packages/package-1/move?from=version-2',
        },
        catalog_url: null,
        stripe_connected: true,
        ...overrides,
    };
}

/** A move in the editor's panel, with its cancel endpoint while scheduled. */
export function moveRow(
    overrides: Partial<PackageMoveRow> = {},
): PackageMoveRow {
    return {
        id: 'move-1',
        status: 'scheduled',
        package: {
            id: 'package-1',
            link_name: 'synthetic-care',
            name: 'Synthetic Care plan',
        },
        from_version: { id: 'version-2', number: 2 },
        to_version: { id: 'version-3', number: 3 },
        effective_on: '2027-01-01',
        message: null,
        changes: portalServiceMove().changes,
        new_price: { amount: 69500, currency: 'USD', label: '$695.00' },
        scheduled_at: '2026-11-09T12:00:00+00:00',
        scheduled_by: 'Synthetic Owner',
        cancelled_at: null,
        completed_at: null,
        counts: { scheduled: 2, applied: 0, skipped: 0, cancelled: 0 },
        subscribers: [],
        version: 'b'.repeat(64),
        cancel_url:
            '/api/v1/workspaces/workspace-1/package-migrations/move-1/cancel',
        ...overrides,
    };
}
