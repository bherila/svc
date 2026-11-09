/**
 * Props of the service package screens (docs/service-packages.md, slice 1b).
 *
 * The server sends everything finished: URLs, the publish refusals with their
 * words, and the vocabularies the editor may offer. Amounts are integer minor
 * units in the package's own currency; durations are whole minutes.
 */

export type PackageComponentTerms = {
    kind: string;
    description: string;
    cadence: string;
    /** Minor units, per one of quantity. */
    amount: number;
    /** A decimal string such as "1.000". */
    quantity: string;
    is_taxable: boolean;
};

export type PackageIntakeQuestion = {
    key: string;
    label: string;
    kind: string;
    required: boolean;
};

export type PackageTerms = {
    name: string;
    summary: string | null;
    body_markdown: string | null;
    agreement_text: string | null;
    currency: string;
    billing_cadence: string;
    hourly_rate_amount: number | null;
    retainer_amount: number | null;
    retainer_minutes: number | null;
    rollover_months: number | null;
    first_cycle_proration: string | null;
    charge_setup_fee_at_signup: boolean;
    charge_first_period: boolean;
    require_autopay: boolean;
    autopay_limit_amount: number | null;
    pay_before_activation: boolean;
    payment_lapse_days: number;
    activation_mode: string;
    invoice_issuing: string;
    notice_days: number;
    minimum_term_months: number | null;
    cancellation_mode: string;
    intake_questions: PackageIntakeQuestion[];
    components: PackageComponentTerms[];
};

export type PackageVersion = {
    id: string;
    number: number;
    published_at: string | null;
    terms: PackageTerms;
    /** Minor units. */
    setup_fee_total: number;
};

export type PackageRefusal = { code: string; message: string };

export type ServicePackage = {
    id: string;
    link_name: string;
    status: string;
    is_listed: boolean;
    portal_addable: boolean;
    /** Only while published. */
    public_url: string | null;
    published: PackageVersion | null;
    draft: PackageVersion | null;
    publish_refusals: PackageRefusal[];
    /** Opaque; sent back as expected_version. */
    version: string;
    created_at: string | null;
    updated_at: string | null;
};

export type PackageListItem = ServicePackage & { edit_url: string };

export type PackagesIndexProps = {
    packages: PackageListItem[];
    new_package_url: string;
    catalog_url: string | null;
    stripe_connected: boolean;
};

export type PackageOptions = {
    billing_cadences: string[];
    component_kinds: string[];
    activation_modes: string[];
    invoice_issuing: string[];
    cancellation_modes: string[];
    question_kinds: string[];
};

export type PackageEditUrls = {
    index: string;
    store: string | null;
    update: string | null;
    publish: string | null;
    retire: string | null;
};

export type PackageEditProps = {
    package: ServicePackage | null;
    /** Term defaults while creating; null when editing. */
    defaults: Partial<PackageTerms> | null;
    options: PackageOptions;
    urls: PackageEditUrls;
    catalog_url: string | null;
    stripe_connected: boolean;
};

export type PublicPackageSummary = {
    name: string;
    summary: string | null;
    currency: string;
    billing_cadence: string;
    /** Minor units billed each cycle at the headline. */
    cycle_amount: number;
    setup_fee_amount: number | null;
    minimum_term_months: number | null;
    url: string;
};

export type PublicPackageDetails = PublicPackageSummary & {
    body_markdown: string | null;
    components: {
        description: string;
        kind: string;
        cadence: string;
        amount: number;
    }[];
    hourly_rate_amount: number | null;
    retainer_amount: number | null;
    retainer_minutes: number | null;
    rollover_months: number | null;
    signup: {
        setup_fee_amount: number | null;
        charges_first_period: boolean;
        requires_autopay: boolean;
        autopay_limit_amount: number | null;
    };
    notice_days: number;
    cancellation_mode: string;
};

export type PublicCatalogProps = {
    workspace: { name: string };
    packages: PublicPackageSummary[];
    sign_in_url: string;
};

export type PublicPackageProps = {
    workspace: { name: string };
    package: PublicPackageDetails;
    catalog_url: string | null;
    sign_in_url: string;
    /** The request form arrives with sign-up requests; null in this slice. */
    request: null;
};
