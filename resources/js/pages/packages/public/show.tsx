import { Head, Link } from '@inertiajs/react';
import { PublicLayout } from '@/components/packages/public-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDuration } from '@/lib/duration';
import { formatMoney } from '@/lib/money';
import {
    cadenceLabel,
    cadencePer,
    priceLine,
    textBlocks,
} from '@/lib/packages';
import type {
    PublicPackageDetails,
    PublicPackageProps,
} from '@/types/packages';

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-1 gap-1 py-3 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-4">
            <dt className="min-w-0 text-sm wrap-anywhere text-muted-foreground">
                {label}
            </dt>
            <dd className="text-sm wrap-anywhere">{children}</dd>
        </div>
    );
}

/** What is charged when someone signs up, in the words a visitor expects. */
function signupSentences(pkg: PublicPackageDetails): string[] {
    const sentences: string[] = [];
    const { signup, currency } = pkg;

    if (signup.setup_fee_amount !== null) {
        sentences.push(
            `${formatMoney(signup.setup_fee_amount, currency)} setup fee by card`,
        );
    }

    if (signup.charges_first_period) {
        sentences.push('the first period in advance');
    }

    if (signup.requires_autopay) {
        sentences.push(
            signup.autopay_limit_amount === null
                ? 'a saved card for autopay'
                : `a saved card for autopay, up to ${formatMoney(signup.autopay_limit_amount, currency)} per invoice`,
        );
    }

    return sentences;
}

export default function PublicPackageShow({
    workspace,
    package: pkg,
    catalog_url: catalogUrl,
    sign_in_url: signInUrl,
}: PublicPackageProps) {
    const blocks = textBlocks(pkg.body_markdown);
    const signup = signupSentences(pkg);
    const commitment = [
        pkg.minimum_term_months === null
            ? null
            : `${pkg.minimum_term_months}-month minimum`,
        pkg.notice_days > 0 ? `${pkg.notice_days} days’ notice` : null,
    ].filter((part) => part !== null);

    return (
        <PublicLayout
            workspaceName={workspace.name}
            catalogUrl={catalogUrl}
            signInUrl={signInUrl}
        >
            <Head title={`${pkg.name} · ${workspace.name}`} />
            <div className="grid grid-cols-1 gap-2">
                <h1 className="text-2xl font-semibold tracking-tight wrap-anywhere">
                    {pkg.name}
                </h1>
                {pkg.summary !== null && (
                    <p className="text-lg wrap-anywhere text-muted-foreground">
                        {pkg.summary}
                    </p>
                )}
            </div>

            {blocks.length > 0 && (
                <div className="grid grid-cols-1 gap-3 text-sm">
                    {blocks.map((block, index) =>
                        block.type === 'list' ? (
                            <ul
                                key={index}
                                className="grid list-disc grid-cols-1 gap-1 ps-5"
                            >
                                {block.items.map((item, at) => (
                                    <li key={at} className="wrap-anywhere">
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p key={index} className="wrap-anywhere">
                                {block.text}
                            </p>
                        ),
                    )}
                </div>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>Price</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl className="grid grid-cols-1 divide-y divide-border">
                        {pkg.components.map((component, index) => (
                            <Row key={index} label={component.description}>
                                <span className="tabular-nums">
                                    {component.kind === 'setup_fee'
                                        ? `${formatMoney(component.amount, pkg.currency)} once`
                                        : priceLine(
                                              component.amount,
                                              pkg.currency,
                                              component.cadence,
                                          )}
                                </span>
                            </Row>
                        ))}
                        {pkg.retainer_amount !== null &&
                            pkg.retainer_amount > 0 && (
                                <Row
                                    label={
                                        pkg.retainer_minutes === null
                                            ? 'Retainer'
                                            : `Retainer · ${formatDuration(pkg.retainer_minutes, 'decimal')} included`
                                    }
                                >
                                    <span className="tabular-nums">
                                        {priceLine(
                                            pkg.retainer_amount,
                                            pkg.currency,
                                            pkg.billing_cadence,
                                        )}
                                    </span>
                                </Row>
                            )}
                        {pkg.rollover_months !== null &&
                            pkg.rollover_months > 0 && (
                                <Row label="Unused hours">
                                    Roll over for {pkg.rollover_months}{' '}
                                    {pkg.rollover_months === 1
                                        ? 'month'
                                        : 'months'}
                                </Row>
                            )}
                        {pkg.hourly_rate_amount !== null && (
                            <Row label="Extra hours">
                                <span className="tabular-nums">
                                    {formatMoney(
                                        pkg.hourly_rate_amount,
                                        pkg.currency,
                                    )}{' '}
                                    per hour
                                </span>
                            </Row>
                        )}
                        <Row label="At sign-up">
                            {signup.length === 0
                                ? 'Nothing is charged at sign-up.'
                                : `${signup.join(', ')}.`}
                        </Row>
                        {commitment.length > 0 && (
                            <Row label="Commitment">
                                {commitment.join(', then ')}
                            </Row>
                        )}
                        <Row label="Billing">
                            {cadenceLabel(pkg.billing_cadence)}, in{' '}
                            {pkg.currency}
                            {pkg.billing_cadence === 'one_time'
                                ? ''
                                : ` (${cadencePer(pkg.billing_cadence)})`}
                        </Row>
                    </dl>
                </CardContent>
            </Card>

            <p className="text-sm text-muted-foreground">
                Already a client?{' '}
                <Link href={signInUrl} className="underline underline-offset-4">
                    Sign in
                </Link>{' '}
                to add this service to your account.
            </p>
        </PublicLayout>
    );
}
