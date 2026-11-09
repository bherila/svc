import { Head, Link } from '@inertiajs/react';
import {
    EngagementAction,
    ProposalDraftForm,
    ScheduleDraftForm,
} from '@/components/agreements/engagement-actions';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

type Proposal = {
    id: string;
    version: string;
    href: string;
    send_href: string | null;
    title: string;
    status: string;
    total_amount: number;
    currency: string;
};

type Agreement = {
    id: string;
    version: string;
    href: string;
    activate_href: string | null;
    /** A service package sign-up's agreement waiting for review: its enrollment's activation, with the enrollment's version. */
    activate_enrollment: { href: string; version: string } | null;
    create_schedule_href: string | null;
    title: string;
    status: string;
    billing_cadence: string;
    currency: string;
};

type BillingSchedule = {
    id: string;
    version: string;
    generate_href: string | null;
    agreement_id: string;
    cadence: string;
    next_run_on: string;
    is_active: boolean;
};

const linkClass = 'underline-offset-4 hover:underline';

function SectionHeading({ children }: { children: React.ReactNode }) {
    return (
        <h2 className="text-[17px] font-semibold tracking-tight md:text-lg">
            {children}
        </h2>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}

/**
 * The Agreements tab of a client: proposals, agreements and billing schedules,
 * with the tools that used to sit on the classic Operations page.
 *
 * Every control is the server's finished URL for a record in a state that
 * accepts it; a viewer who may not manage the workspace gets the lists and no
 * controls. Each action keeps its own confirmation, because what it does is
 * unchanged: activation enables billing, and generating issues invoices.
 */
export default function ClientAgreements({
    company,
    version,
    timezone,
    create_proposal_href: createProposalHref,
    proposals,
    agreements,
    billing_schedules: billingSchedules,
}: {
    company: { id: string; name: string };
    /** The company's version, which a drafted proposal is checked against. */
    version: string;
    /** The workspace's calendar; a schedule's first run defaults on it. */
    timezone: string;
    create_proposal_href: string | null;
    proposals: Proposal[];
    agreements: Agreement[];
    billing_schedules: BillingSchedule[];
}) {
    const agreementTitles = new Map(agreements.map((a) => [a.id, a.title]));

    return (
        <WorkspaceShell activeModule="agreements">
            <Head title={`${company.name} agreements`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-8 py-8')}
            >
                <h1 className="text-2xl font-semibold tracking-tight">
                    Agreements
                </h1>

                <section
                    className="grid grid-cols-1 gap-3"
                    aria-labelledby="proposals"
                >
                    <div id="proposals" className="scroll-mt-20">
                        <SectionHeading>Proposals</SectionHeading>
                    </div>
                    {createProposalHref !== null && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Draft a proposal</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ProposalDraftForm
                                    href={createProposalHref}
                                    companyId={company.id}
                                    version={version}
                                />
                            </CardContent>
                        </Card>
                    )}
                    <Card>
                        <CardContent>
                            {proposals.length === 0 ? (
                                <Empty>No proposals yet.</Empty>
                            ) : (
                                <ul className="grid grid-cols-1 divide-y divide-border">
                                    {proposals.map((proposal) => (
                                        <li
                                            key={proposal.id}
                                            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0 flex-1 basis-56">
                                                <Link
                                                    href={proposal.href}
                                                    className={cn(
                                                        linkClass,
                                                        'font-medium wrap-anywhere',
                                                    )}
                                                >
                                                    {proposal.title}
                                                </Link>
                                                <p className="text-sm wrap-anywhere text-muted-foreground">
                                                    {formatMoney(
                                                        proposal.total_amount,
                                                        proposal.currency,
                                                    )}
                                                    {' · '}
                                                    {statusLabel(
                                                        proposal.status,
                                                    )}
                                                </p>
                                            </div>
                                            {proposal.send_href !== null && (
                                                <EngagementAction
                                                    href={proposal.send_href}
                                                    version={proposal.version}
                                                    label="Send"
                                                    description="Make this proposal available for client review and acceptance."
                                                />
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </section>

                <section
                    className="grid grid-cols-1 gap-3"
                    aria-labelledby="agreements"
                >
                    <div id="agreements" className="scroll-mt-20">
                        <SectionHeading>Agreements</SectionHeading>
                    </div>
                    {agreements.length === 0 && (
                        <Card>
                            <CardContent>
                                <Empty>No agreements yet.</Empty>
                            </CardContent>
                        </Card>
                    )}
                    {agreements.map((agreement) => (
                        <Card key={agreement.id}>
                            <CardHeader>
                                <CardTitle className="wrap-anywhere">
                                    <Link
                                        href={agreement.href}
                                        className={linkClass}
                                    >
                                        {agreement.title}
                                    </Link>
                                </CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    {statusLabel(agreement.billing_cadence)}
                                    {' · '}
                                    {statusLabel(agreement.status)}
                                </p>
                            </CardHeader>
                            {(agreement.activate_href !== null ||
                                agreement.activate_enrollment !== null ||
                                agreement.create_schedule_href !== null) && (
                                <CardContent className="grid grid-cols-1 gap-3">
                                    {agreement.activate_href !== null && (
                                        <div className="flex flex-wrap gap-2">
                                            <EngagementAction
                                                href={agreement.activate_href}
                                                version={agreement.version}
                                                label="Activate"
                                                description="Activate this agreement using its current terms. This enables its billing workflow."
                                            />
                                        </div>
                                    )}
                                    {agreement.activate_enrollment !== null && (
                                        <div className="flex flex-wrap gap-2">
                                            <EngagementAction
                                                href={
                                                    agreement
                                                        .activate_enrollment
                                                        .href
                                                }
                                                version={
                                                    agreement
                                                        .activate_enrollment
                                                        .version
                                                }
                                                label="Activate service"
                                                description="The client signed this agreement from a service package. Activating it starts the service today in the workspace’s time zone, signs the agreement in the client’s name as they typed it, and emails the client."
                                            />
                                        </div>
                                    )}
                                    {agreement.create_schedule_href !==
                                        null && (
                                        <ScheduleDraftForm
                                            href={
                                                agreement.create_schedule_href
                                            }
                                            companyId={company.id}
                                            timezone={timezone}
                                            agreement={agreement}
                                        />
                                    )}
                                </CardContent>
                            )}
                        </Card>
                    ))}
                </section>

                <section
                    className="grid grid-cols-1 gap-3"
                    aria-labelledby="billing-schedules"
                >
                    <div id="billing-schedules" className="scroll-mt-20">
                        <SectionHeading>Billing schedules</SectionHeading>
                    </div>
                    <Card>
                        <CardContent>
                            {billingSchedules.length === 0 ? (
                                <Empty>No billing schedules yet.</Empty>
                            ) : (
                                <ul className="grid grid-cols-1 divide-y divide-border">
                                    {billingSchedules.map((schedule) => (
                                        <li
                                            key={schedule.id}
                                            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0 flex-1 basis-56">
                                                <p className="font-medium wrap-anywhere">
                                                    {agreementTitles.get(
                                                        schedule.agreement_id,
                                                    ) ??
                                                        statusLabel(
                                                            schedule.cadence,
                                                        )}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {statusLabel(
                                                        schedule.cadence,
                                                    )}
                                                    {' · next '}
                                                    {formatDay(
                                                        schedule.next_run_on,
                                                    )}
                                                    {schedule.is_active
                                                        ? ''
                                                        : ' · paused'}
                                                </p>
                                            </div>
                                            {schedule.generate_href !==
                                                null && (
                                                <EngagementAction
                                                    href={
                                                        schedule.generate_href
                                                    }
                                                    version={schedule.version}
                                                    label="Generate due"
                                                    description="Generate invoices for this billing schedule through today. Review its next run and terms before confirming."
                                                />
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </section>
            </main>
        </WorkspaceShell>
    );
}
