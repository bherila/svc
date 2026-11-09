import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    FieldError,
    FormField,
    fieldErrorProps,
} from '@/components/form-field';
import { ClientTermsList } from '@/components/packages/client-terms';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatTimestampDate } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { termLabel, textBlocks } from '@/lib/packages';
import { cn } from '@/lib/utils';
import type { PortalServiceProps } from '@/types/packages';

type Step = { key: string; label: string };

/** Where the service is on the way to starting; -1 when it is no longer on it. */
function currentStep(status: string, steps: Step[]): number {
    switch (status) {
        case 'offered':
            return 0;
        case 'awaiting_review':
            return steps.findIndex((step) => step.key === 'review');
        case 'active':
            return steps.length - 1;
        default:
            return -1;
    }
}

function Steps({ steps, current }: { steps: Step[]; current: number }) {
    return (
        <ol
            aria-label="Steps"
            className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
        >
            {steps.map((step, index) => (
                <li
                    key={step.key}
                    aria-current={index === current ? 'step' : undefined}
                    className={cn(
                        'flex items-center gap-2',
                        index === current
                            ? 'font-semibold'
                            : index < current
                              ? 'text-foreground'
                              : 'text-muted-foreground',
                    )}
                >
                    {index > 0 && <span aria-hidden>→</span>}
                    <span>
                        {index < current ? '✓ ' : ''}
                        {step.label}
                    </span>
                </li>
            ))}
        </ol>
    );
}

function TextBlocks({ text }: { text: string | null }) {
    return (
        <>
            {textBlocks(text).map((block, index) =>
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
        </>
    );
}

function SignForm({
    acceptUrl,
    companyName,
}: {
    acceptUrl: string;
    companyName: string;
}) {
    const [name, setName] = useState('');
    const [title, setTitle] = useState('');
    const [accepted, setAccepted] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // The server checks the same rules; saying so here saves the round trip.
        const missing: Record<string, string> = {};

        if (name.trim() === '') {
            missing.signer_name = 'Enter your full name.';
        }

        if (!accepted) {
            missing.accepted_terms = 'Tick the box to accept the agreement.';
        }

        setErrors(missing);

        if (Object.keys(missing).length > 0) {
            return;
        }

        setBusy(true);
        router.post(
            acceptUrl,
            {
                signer_name: name.trim(),
                ...(title.trim() === '' ? {} : { signer_title: title.trim() }),
                accepted_terms: true,
            },
            {
                preserveScroll: true,
                onError: (serverErrors) => setErrors(serverErrors),
                onFinish: () => setBusy(false),
            },
        );
    };

    const checkbox = 'accepted-terms';

    return (
        <form onSubmit={submit} noValidate className="grid grid-cols-1 gap-4">
            <FormField label="Full name" error={errors.signer_name}>
                {(id, aria) => (
                    <Input
                        id={id}
                        {...aria}
                        autoComplete="name"
                        maxLength={200}
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                    />
                )}
            </FormField>
            <FormField label="Title (optional)" error={errors.signer_title}>
                {(id, aria) => (
                    <Input
                        id={id}
                        {...aria}
                        autoComplete="organization-title"
                        maxLength={200}
                        value={title}
                        onChange={(event) => setTitle(event.target.value)}
                    />
                )}
            </FormField>
            <div className="grid grid-cols-1 gap-1.5">
                <label
                    htmlFor={checkbox}
                    className="flex min-w-0 items-start gap-2 text-sm"
                >
                    <input
                        id={checkbox}
                        type="checkbox"
                        className="mt-0.5"
                        checked={accepted}
                        {...fieldErrorProps(checkbox, errors.accepted_terms)}
                        onChange={(event) => setAccepted(event.target.checked)}
                    />
                    <span className="min-w-0 wrap-anywhere">
                        I’ve read the agreement and accept it on behalf of{' '}
                        {companyName}.
                    </span>
                </label>
                {errors.accepted_terms !== undefined && (
                    <FieldError id={checkbox}>
                        {errors.accepted_terms}
                    </FieldError>
                )}
            </div>
            {errors.acceptance !== undefined && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {errors.acceptance}
                </p>
            )}
            <div>
                <Button type="submit" disabled={busy}>
                    Accept and sign
                </Button>
            </div>
        </form>
    );
}

/**
 * Review and sign one service (ACC-1): its terms and agreement, a signature
 * form for a company-wide contact, and afterwards where the service stands.
 *
 * Everything shown is read from the server. When a newer version was published
 * after the offer, the terms shown are what an acceptance signs now, and what
 * changed is said before the form (EDGE-7).
 */
export default function PortalService({
    company,
    service,
    offered_terms: offeredTerms,
    changed_terms: changedTerms,
    accept_url: acceptUrl,
    accept_refusal: acceptRefusal,
}: PortalServiceProps) {
    const { terms, acceptance } = service;
    const steps: Step[] = [
        { key: 'sign', label: 'Review and sign' },
        ...(terms.activation_mode === 'automatic'
            ? []
            : [{ key: 'review', label: 'Review by us' }]),
        { key: 'active', label: 'Active' },
    ];
    const current = currentStep(service.status, steps);

    return (
        <WorkspaceShell activeModule="services">
            <Head title={`${service.name} · ${company.name}`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <div className="grid grid-cols-1 gap-3">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h1 className="min-w-0 text-2xl font-semibold tracking-tight wrap-anywhere">
                            {service.name}
                        </h1>
                        <Badge variant="outline">
                            {statusLabel(service.status)}
                        </Badge>
                    </div>
                    {current >= 0 && <Steps steps={steps} current={current} />}
                </div>

                {acceptance !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {service.status === 'active'
                                    ? 'Thanks, you’re all set'
                                    : 'Thanks, we have your signature'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-2 text-sm">
                            <p className="wrap-anywhere">
                                Signed by {acceptance.signer_name}
                                {acceptance.signer_title === null
                                    ? ''
                                    : `, ${acceptance.signer_title}`}{' '}
                                on {formatTimestampDate(acceptance.accepted_at)}
                                .
                            </p>
                            <p className="wrap-anywhere text-muted-foreground">
                                {service.status === 'active'
                                    ? 'Your service is active.'
                                    : service.status === 'awaiting_review'
                                      ? 'We review new services before they start. You’ll get an email when it’s active.'
                                      : `Its status is ${statusLabel(service.status).toLowerCase()}.`}
                            </p>
                            {service.agreement_url !== null && (
                                <p>
                                    <Link
                                        href={service.agreement_url}
                                        className="underline underline-offset-4"
                                    >
                                        View the signed agreement
                                    </Link>
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                {changedTerms.length > 0 && (
                    <Card role="note" className="bg-muted/50">
                        <CardContent className="grid grid-cols-1 gap-2 text-sm">
                            <p className="font-medium wrap-anywhere">
                                Updated since you were offered:{' '}
                                {changedTerms.map(termLabel).join(', ')}
                            </p>
                            <p className="text-muted-foreground">
                                {acceptance === null
                                    ? 'What is shown below is what signing accepts now.'
                                    : 'You signed the terms shown below.'}
                            </p>
                            {offeredTerms !== null && (
                                <details className="grid grid-cols-1 gap-2">
                                    <summary className="cursor-pointer underline underline-offset-4">
                                        The terms as first offered
                                    </summary>
                                    <div className="grid grid-cols-1 gap-3 pt-2">
                                        <ClientTermsList terms={offeredTerms} />
                                        {offeredTerms.agreement_text !==
                                            null && (
                                            <div
                                                tabIndex={0}
                                                role="region"
                                                aria-label="Agreement text as first offered"
                                                className="max-h-40 overflow-y-auto text-sm wrap-anywhere whitespace-pre-wrap focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            >
                                                {offeredTerms.agreement_text}
                                            </div>
                                        )}
                                    </div>
                                </details>
                            )}
                        </CardContent>
                    </Card>
                )}

                {terms.body_markdown !== null && terms.body_markdown !== '' && (
                    <div className="grid grid-cols-1 gap-3 text-sm">
                        <TextBlocks text={terms.body_markdown} />
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>
                            Agreement · v{service.version_number}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4">
                        {terms.agreement_text !== null &&
                        terms.agreement_text !== '' ? (
                            <div
                                tabIndex={0}
                                role="region"
                                aria-label="Agreement text"
                                className="max-h-72 overflow-y-auto rounded-lg bg-muted/40 p-4 text-sm wrap-anywhere whitespace-pre-wrap focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {terms.agreement_text}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                This service has no separate agreement text. The
                                terms below are what you accept.
                            </p>
                        )}
                        <ClientTermsList terms={terms} />
                    </CardContent>
                </Card>

                {service.status === 'offered' &&
                    (acceptUrl === null ? (
                        <Card>
                            <CardContent className="text-sm wrap-anywhere text-muted-foreground">
                                {acceptRefusal?.message ??
                                    `Someone with company-wide access signs this for ${company.name}.`}
                            </CardContent>
                        </Card>
                    ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle>Sign</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <SignForm
                                    acceptUrl={acceptUrl}
                                    companyName={company.name}
                                />
                            </CardContent>
                        </Card>
                    ))}
            </main>
        </WorkspaceShell>
    );
}
