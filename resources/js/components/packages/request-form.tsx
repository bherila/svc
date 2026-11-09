import { CheckIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { apiRequest } from '@/lib/api';
import type { PublicRequestForm } from '@/types/packages';

const REQUIRED = 'Please fill this in.';
const EXPIRED =
    'This page has been open too long. Reload it and send the request again.';

/** The first message of each field the server named, by field. */
function firstMessages(
    errors: Record<string, string[]> | undefined,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors ?? {}).flatMap(([key, messages]) =>
            messages[0] === undefined ? [] : [[key, messages[0]]],
        ),
    );
}

/**
 * "Request this service": the public sign-up request (PUB-4).
 *
 * Nothing here is charged or created; the request only reaches the owner. The
 * honeypot is an input people never reach - off-screen rather than hidden, so
 * a robot that skips `display: none` fields still finds it - and no script or
 * font comes from anywhere else (PUB-7). Every refusal other than a field the
 * visitor can correct shows one generic sentence, whichever guard stopped it.
 */
export function RequestForm({ form }: { form: PublicRequestForm }) {
    const [company, setCompany] = useState('');
    const [name, setName] = useState(form.prefill?.contact_name ?? '');
    const [email, setEmail] = useState(form.prefill?.email ?? '');
    const [phone, setPhone] = useState('');
    const [answers, setAnswers] = useState<Record<string, string>>({});
    // Own properties only: a question keyed `constructor` or `__proto__` must
    // read as unanswered, not as something inherited from Object (Codex P2 on #666).
    const answerTo = (key: string): string =>
        Object.hasOwn(answers, key) ? answers[key] : '';
    const [trap, setTrap] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [refusal, setRefusal] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState<{ name: string; email: string } | null>(
        null,
    );
    const confirmation = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (sent !== null) {
            confirmation.current?.focus();
        }
    }, [sent]);

    if (sent !== null) {
        return (
            <Card aria-label="After sending">
                <CardHeader>
                    <CardTitle
                        ref={confirmation}
                        tabIndex={-1}
                        className="flex items-center gap-2 outline-none"
                    >
                        <CheckIcon aria-hidden className="size-4" />
                        Request sent
                    </CardTitle>
                </CardHeader>
                <CardContent
                    role="status"
                    className="text-sm wrap-anywhere text-muted-foreground"
                >
                    Thanks, {sent.name}. We’ll review it and email{' '}
                    <strong className="font-semibold text-foreground">
                        {sent.email}
                    </strong>
                    . You can close this page.
                </CardContent>
            </Card>
        );
    }

    const submit = async () => {
        if (busy) {
            return;
        }

        const missing: Record<string, string> = {};

        if (company.trim() === '') {
            missing.company_name = REQUIRED;
        }

        if (name.trim() === '') {
            missing.contact_name = REQUIRED;
        }

        if (email.trim() === '') {
            missing.email = REQUIRED;
        }

        for (const question of form.questions) {
            if (question.required && answerTo(question.key).trim() === '') {
                missing[`answers.${question.key}`] = REQUIRED;
            }
        }

        setErrors(missing);
        setRefusal(null);

        if (Object.keys(missing).length > 0) {
            return;
        }

        setBusy(true);
        const result = await apiRequest('POST', form.submit_url, {
            form_token: form.form_token,
            company_name: company.trim(),
            contact_name: name.trim(),
            email: email.trim(),
            phone: phone.trim(),
            answers: Object.fromEntries(
                form.questions.map((question) => [
                    question.key,
                    answerTo(question.key).trim(),
                ]),
            ),
            [form.honeypot_field]: trap,
        });
        setBusy(false);

        if (result.ok) {
            setSent({ name: name.trim(), email: email.trim() });

            return;
        }

        if (result.status === 419) {
            setRefusal(EXPIRED);

            return;
        }

        const fields = firstMessages(result.errors);

        if (result.status === 422 && Object.keys(fields).length > 0) {
            setErrors(fields);

            return;
        }

        // The server's one sentence for every guard; nothing is explained.
        setRefusal(result.message);
    };

    return (
        <Card aria-label="Request this service">
            <CardHeader>
                <CardTitle>Request this service</CardTitle>
                <p className="text-sm text-muted-foreground">
                    Nothing is charged now. If we accept, you’ll get an email to
                    set up your account, review the agreement and pay.
                </p>
            </CardHeader>
            <CardContent>
                <form
                    noValidate
                    className="mt-2 grid max-w-xl grid-cols-1 gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <FormField label="Company name" error={errors.company_name}>
                        {(id, aria) => (
                            <Input
                                id={id}
                                {...aria}
                                required
                                maxLength={255}
                                autoComplete="organization"
                                value={company}
                                onChange={(event) =>
                                    setCompany(event.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField
                            label="Your name"
                            error={errors.contact_name}
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    required
                                    maxLength={255}
                                    autoComplete="name"
                                    value={name}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Phone (optional)"
                            error={errors.phone}
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    type="tel"
                                    maxLength={64}
                                    autoComplete="tel"
                                    value={phone}
                                    onChange={(event) =>
                                        setPhone(event.target.value)
                                    }
                                />
                            )}
                        </FormField>
                    </div>
                    <FormField label="Email" error={errors.email}>
                        {(id, aria) => (
                            <Input
                                id={id}
                                {...aria}
                                type="email"
                                required
                                maxLength={255}
                                autoComplete="email"
                                value={email}
                                onChange={(event) =>
                                    setEmail(event.target.value)
                                }
                            />
                        )}
                    </FormField>

                    {form.questions.length > 0 && (
                        <hr className="border-border" />
                    )}
                    {form.questions.map((question) => {
                        const label = question.required
                            ? question.label
                            : `${question.label} (optional)`;
                        const error = errors[`answers.${question.key}`];
                        const set = (value: string) =>
                            setAnswers((current) => ({
                                ...current,
                                [question.key]: value,
                            }));

                        return (
                            <FormField
                                key={question.key}
                                label={label}
                                error={error}
                            >
                                {(id, aria) =>
                                    question.kind === 'paragraph' ? (
                                        <Textarea
                                            id={id}
                                            {...aria}
                                            required={question.required}
                                            rows={3}
                                            maxLength={question.max_length}
                                            value={answerTo(question.key)}
                                            onChange={(event) =>
                                                set(event.target.value)
                                            }
                                        />
                                    ) : (
                                        <Input
                                            id={id}
                                            {...aria}
                                            required={question.required}
                                            maxLength={question.max_length}
                                            value={answerTo(question.key)}
                                            onChange={(event) =>
                                                set(event.target.value)
                                            }
                                        />
                                    )
                                }
                            </FormField>
                        );
                    })}

                    {/* Never shown to people: off-screen, out of the tab order and unannounced. */}
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute -start-[9999px] top-auto h-px w-px overflow-hidden"
                    >
                        <label>
                            Leave this field empty
                            <input
                                type="text"
                                name={form.honeypot_field}
                                tabIndex={-1}
                                autoComplete="off"
                                value={trap}
                                onChange={(event) =>
                                    setTrap(event.target.value)
                                }
                            />
                        </label>
                    </div>

                    {refusal !== null && (
                        <p
                            role="alert"
                            className="text-sm wrap-anywhere text-destructive"
                        >
                            {refusal}
                        </p>
                    )}
                    <Button
                        type="submit"
                        disabled={busy}
                        className="min-h-11 w-full md:min-h-0"
                    >
                        Send request
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        By sending this you agree we may contact you about this
                        request.
                    </p>
                </form>
            </CardContent>
        </Card>
    );
}
