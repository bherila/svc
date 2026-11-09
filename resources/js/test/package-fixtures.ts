import type {
    PackageComponentTerms,
    PackageEditProps,
    PackageListItem,
    PackageTerms,
    PackageVersion,
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
