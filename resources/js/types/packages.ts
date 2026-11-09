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
    /** One billing cycle at the headline, from the server: recurring components on the package's cadence plus the retainer. */
    cycle_amount: number;
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
    /** The next page of packages, when there is one. */
    next_page_url: string | null;
    /** Back to the first page, when this is a later one. */
    first_page_url: string | null;
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
    request: PublicRequestForm;
};

export type PublicRequestQuestion = {
    key: string;
    label: string;
    kind: string;
    required: boolean;
    max_length: number;
};

/** What the visitor's request form is built from; the server sends it finished. */
export type PublicRequestForm = {
    submit_url: string;
    /** Opaque; sent back with the submission. */
    form_token: string;
    /** The name of a field people never see; a robot fills it in. */
    honeypot_field: string;
    questions: PublicRequestQuestion[];
    /** A signed-in visitor's own name and email, to start from. */
    prefill: { contact_name: string; email: string } | null;
};

export type PackageRequestUrls = {
    approve: string | null;
    decline: string | null;
    erase: string | null;
};

export type PackageRequestAnswer = {
    key: string;
    question: string;
    answer: string | null;
};

/** Everything the visitor typed: text to show, never markup to interpret (PUB-6). */
export type PackageRequestUntrusted = {
    company_name: string;
    contact_name: string;
    email: string;
    phone: string | null;
    answers: PackageRequestAnswer[];
};

export type PackageRequestRow = {
    id: string;
    status: string;
    package: {
        id: string;
        name: string;
        link_name: string;
        version_number: number;
    };
    submitted_at: string;
    decided_at: string | null;
    decline_reason: string | null;
    client: { id: string; name: string } | null;
    suggested_client: { id: string; name: string } | null;
    erased_at: string | null;
    /** Null once the request was erased. */
    untrusted: PackageRequestUntrusted | null;
    /** Opaque; sent back as expected_version. */
    version: string;
    urls: PackageRequestUrls;
};

export type PackageRequestsProps = {
    requests: PackageRequestRow[];
    clients: { id: string; name: string }[];
    catalog_url: string | null;
    stripe_connected: boolean;
    requests_url: string;
    open_requests_count: number;
};

/**
 * What a client reads of a version's terms in the portal (slice 3a): the offer
 * and the agreement text, never the operator's settings.
 */
export type ClientTerms = {
    name: string;
    summary: string | null;
    body_markdown: string | null;
    agreement_text: string | null;
    currency: string;
    billing_cadence: string;
    /** Minor units; one billing cycle at the headline. */
    cycle_amount: number;
    setup_fee_amount: number | null;
    components: Pick<
        PackageComponentTerms,
        'kind' | 'description' | 'cadence' | 'amount'
    >[];
    hourly_rate_amount: number | null;
    retainer_amount: number | null;
    retainer_minutes: number | null;
    rollover_months: number | null;
    activation_mode: string;
    notice_days: number;
    minimum_term_months: number | null;
    cancellation_mode: string;
};

/** One of a company's services as the portal presents it. */
export type PortalService = {
    id: string;
    status: string;
    company: { id: string; name: string };
    project_id: string;
    name: string;
    version_number: number;
    /** Signed on a newer version than the one offered (EDGE-7). */
    version_changed: boolean;
    terms: ClientTerms;
    acceptance: {
        accepted_at: string;
        signer_name: string;
        signer_title: string | null;
    } | null;
    activated_at: string | null;
    /** The agreement's own portal page, once it is active. */
    agreement_url: string | null;
    /** This service's own portal page: review and sign while offered. */
    url: string;
};

export type PortalAddablePackage = {
    id: string;
    name: string;
    summary: string | null;
    currency: string;
    billing_cadence: string;
    cycle_amount: number;
    setup_fee_amount: number | null;
    minimum_term_months: number | null;
    url: string;
    add_refusal: PackageRefusal | null;
    /** Where adding posts; null when it cannot be added now. */
    add_url: string | null;
};

export type PortalServicesProps = {
    company: { id: string; name: string };
    services: PortalService[];
    /** Null for a contact who may not add; the section is then not drawn. */
    addable_packages: PortalAddablePackage[] | null;
};

export type PortalServiceProps = {
    company: { id: string; name: string };
    service: PortalService;
    /** Present only when a newer version was published after the offer (EDGE-7). */
    offered_terms: ClientTerms | null;
    /** The terms (by their server keys) that differ between the offer and what is signed. */
    changed_terms: string[];
    /** Null while the viewer may not sign, or nothing is open to sign. */
    accept_url: string | null;
    accept_refusal: PackageRefusal | null;
};
