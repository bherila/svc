import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { WebhookHealth } from '@/components/workspaces/webhook-health';
import type { WebhookHealthView } from '@/components/workspaces/webhook-health';
import { apiRequest } from '@/lib/api';
import { statusLabel } from '@/lib/labels';

export interface PaymentConnectionView {
    display_name: string | null;
    environment: string | null;
    key_hint: string | null;
    location_id: string | null;
    publishable_key: string | null;
    reused_from: string | null;
    usable: boolean;
    webhook_url: string | null;
    /** Stripe only, and only for a connection with a key of its own. */
    webhook?: WebhookHealthView | null;
}

export interface PaymentProcessorView {
    processor: 'stripe' | 'square';
    label: string;
    connection: PaymentConnectionView | null;
    environment_path_active: boolean;
    reusable: { id: string; label: string }[];
    save_url: string | null;
    reuse_url: string | null;
    remove_url: string | null;
}

const ENVIRONMENTS = [
    { value: 'production', label: 'Production' },
    { value: 'sandbox', label: 'Sandbox' },
];

type Errors = Record<string, string>;

function ProcessorCard({ view }: { view: PaymentProcessorView }) {
    const [secret, setSecret] = useState('');
    const [webhookSecret, setWebhookSecret] = useState('');
    const [publishableKey, setPublishableKey] = useState('');
    const [locationId, setLocationId] = useState('');
    const [environment, setEnvironment] = useState('production');
    const [source, setSource] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);
    const [replacing, setReplacing] = useState(false);
    const connection = view.connection;
    const square = view.processor === 'square';

    async function send(
        method: 'POST' | 'DELETE',
        url: string,
        data: Record<string, string>,
    ) {
        setBusy(true);

        const result = await apiRequest(method, url, data);

        setBusy(false);

        if (!result.ok) {
            const next: Errors = {};

            for (const [field, messages] of Object.entries(
                result.errors ?? {},
            )) {
                next[field] = messages[0] ?? result.message;
            }

            setErrors(
                Object.keys(next).length > 0
                    ? next
                    : { secret: result.message },
            );

            return;
        }

        // The key leaves the browser's memory as soon as it is saved.
        setSecret('');
        setWebhookSecret('');
        setPublishableKey('');
        setErrors({});
        setReplacing(false);
        router.reload();
    }

    function submit(url: string, data: Record<string, string>) {
        void send('POST', url, data);
    }

    function saveKey() {
        if (view.save_url === null) {
            return;
        }

        submit(
            view.save_url,
            square
                ? {
                      secret,
                      webhook_secret: webhookSecret,
                      location_id: locationId,
                      environment,
                  }
                : { secret, publishable_key: publishableKey },
        );
    }

    const showForm =
        view.save_url !== null && (connection === null || replacing);

    return (
        <Card data-test={`payment-${view.processor}`}>
            <CardHeader>
                <CardTitle>{view.label}</CardTitle>
                <CardDescription>
                    {connection === null
                        ? view.environment_path_active
                            ? `Using the deployment's ${view.label} account until a workspace connection is set up.`
                            : `Not connected. Online payments with ${view.label} are off.`
                        : connection.usable
                          ? 'Connected.'
                          : 'The connection this workspace borrows is no longer active.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid min-w-0 grid-cols-1 gap-4">
                {connection !== null && (
                    <dl className="grid min-w-0 grid-cols-1 gap-2 text-sm">
                        {connection.display_name !== null && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Account
                                </dt>
                                <dd className="wrap-anywhere">
                                    {connection.display_name}
                                </dd>
                            </div>
                        )}
                        {connection.key_hint !== null && (
                            <div>
                                <dt className="text-muted-foreground">Key</dt>
                                <dd
                                    className="font-mono wrap-anywhere"
                                    data-test={`payment-${view.processor}-hint`}
                                >
                                    {connection.key_hint}
                                </dd>
                            </div>
                        )}
                        {connection.publishable_key !== null && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Publishable key
                                </dt>
                                <dd
                                    className="font-mono wrap-anywhere"
                                    data-test="payment-stripe-publishable"
                                >
                                    {connection.publishable_key}
                                </dd>
                            </div>
                        )}
                        {connection.location_id !== null && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Location
                                </dt>
                                <dd className="font-mono wrap-anywhere">
                                    {connection.location_id}
                                </dd>
                            </div>
                        )}
                        {connection.environment !== null && (
                            <div>
                                <dt className="text-muted-foreground">Mode</dt>
                                <dd>{statusLabel(connection.environment)}</dd>
                            </div>
                        )}
                        {connection.reused_from !== null && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Shared from
                                </dt>
                                <dd className="wrap-anywhere">
                                    {connection.reused_from}
                                </dd>
                            </div>
                        )}
                        {connection.webhook_url !== null && square && (
                            <div>
                                <dt className="text-muted-foreground">
                                    Webhook URL
                                </dt>
                                <dd className="font-mono wrap-anywhere">
                                    {connection.webhook_url}
                                </dd>
                            </div>
                        )}
                    </dl>
                )}

                {connection?.webhook != null && !replacing && (
                    <WebhookHealth health={connection.webhook} />
                )}

                {showForm && (
                    <form
                        className="grid min-w-0 grid-cols-1 gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            saveKey();
                        }}
                    >
                        <div className="grid min-w-0 grid-cols-1 gap-2">
                            <Label htmlFor={`${view.processor}-secret`}>
                                {square
                                    ? 'Square access token'
                                    : 'Stripe restricted key'}
                            </Label>
                            <Input
                                id={`${view.processor}-secret`}
                                type="password"
                                autoComplete="off"
                                value={secret}
                                onChange={(event) =>
                                    setSecret(event.target.value)
                                }
                            />
                            {errors.secret !== undefined && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {errors.secret}
                                </p>
                            )}
                        </div>
                        {!square && (
                            <div className="grid min-w-0 grid-cols-1 gap-2">
                                <Label htmlFor="stripe-publishable">
                                    Stripe publishable key
                                </Label>
                                <Input
                                    id="stripe-publishable"
                                    autoComplete="off"
                                    placeholder="pk_live_… or pk_test_…"
                                    value={publishableKey}
                                    onChange={(event) =>
                                        setPublishableKey(event.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Optional. Clients are offered card payment
                                    only when this is set. It must be in the
                                    same mode (live or test) as the restricted
                                    key. When you save, SVC verifies it against
                                    the restricted key&apos;s account with a
                                    short-lived SetupIntent that is cancelled
                                    straight away, so the restricted key needs
                                    the SetupIntents: Write permission.
                                </p>
                                {errors.publishable_key !== undefined && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.publishable_key}
                                    </p>
                                )}
                            </div>
                        )}
                        {square && (
                            <>
                                <div className="grid min-w-0 grid-cols-1 gap-2">
                                    <Label htmlFor="square-location">
                                        Location ID
                                    </Label>
                                    <Input
                                        id="square-location"
                                        value={locationId}
                                        onChange={(event) =>
                                            setLocationId(event.target.value)
                                        }
                                    />
                                    {errors.location_id !== undefined && (
                                        <p
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {errors.location_id}
                                        </p>
                                    )}
                                </div>
                                <div className="grid min-w-0 grid-cols-1 gap-2">
                                    <Label htmlFor="square-environment">
                                        Environment
                                    </Label>
                                    <Select
                                        items={ENVIRONMENTS}
                                        value={environment}
                                        onValueChange={(value) => {
                                            if (value !== null) {
                                                setEnvironment(value);
                                            }
                                        }}
                                    >
                                        <SelectTrigger id="square-environment">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {ENVIRONMENTS.map((item) => (
                                                <SelectItem
                                                    key={item.value}
                                                    value={item.value}
                                                >
                                                    {item.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid min-w-0 grid-cols-1 gap-2">
                                    <Label htmlFor="square-signature">
                                        Webhook signature key
                                    </Label>
                                    <Input
                                        id="square-signature"
                                        type="password"
                                        autoComplete="off"
                                        value={webhookSecret}
                                        onChange={(event) =>
                                            setWebhookSecret(event.target.value)
                                        }
                                    />
                                    {errors.webhook_secret !== undefined && (
                                        <p
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {errors.webhook_secret}
                                        </p>
                                    )}
                                </div>
                            </>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                disabled={busy || secret === ''}
                            >
                                {connection === null
                                    ? 'Connect'
                                    : 'Replace key'}
                            </Button>
                            {replacing && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setReplacing(false)}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>
                )}

                {connection === null &&
                    view.reuse_url !== null &&
                    view.reusable.length > 0 && (
                        <div className="grid min-w-0 grid-cols-1 gap-2">
                            <Label htmlFor={`${view.processor}-reuse`}>
                                Or reuse a connection
                            </Label>
                            <Select
                                items={view.reusable.map((item) => ({
                                    value: item.id,
                                    label: item.label,
                                }))}
                                value={source}
                                onValueChange={(value) =>
                                    setSource(value ?? '')
                                }
                            >
                                <SelectTrigger id={`${view.processor}-reuse`}>
                                    <SelectValue placeholder="Choose a connection" />
                                </SelectTrigger>
                                <SelectContent className="w-auto max-w-[min(28rem,calc(100vw-2rem))] min-w-(--anchor-width)">
                                    {view.reusable.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={item.id}
                                            className="*:first:shrink *:first:wrap-anywhere *:first:whitespace-normal"
                                        >
                                            {item.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.source !== undefined && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {errors.source}
                                </p>
                            )}
                            <div>
                                <Button
                                    variant="outline"
                                    disabled={busy || source === ''}
                                    onClick={() => {
                                        if (view.reuse_url !== null) {
                                            submit(view.reuse_url, { source });
                                        }
                                    }}
                                >
                                    Use this connection
                                </Button>
                            </div>
                        </div>
                    )}

                {connection !== null &&
                    view.save_url !== null &&
                    !replacing && (
                        <div className="flex flex-wrap gap-2">
                            {connection.reused_from === null && (
                                <Button
                                    variant="outline"
                                    onClick={() => setReplacing(true)}
                                >
                                    Replace key
                                </Button>
                            )}
                            {view.remove_url !== null && (
                                <Button
                                    variant="destructive"
                                    disabled={busy}
                                    onClick={() => {
                                        if (view.remove_url !== null) {
                                            void send(
                                                'DELETE',
                                                view.remove_url,
                                                {},
                                            );
                                        }
                                    }}
                                >
                                    Disconnect
                                </Button>
                            )}
                        </div>
                    )}
            </CardContent>
        </Card>
    );
}

export function PaymentConnections({
    processors,
}: {
    processors: PaymentProcessorView[];
}) {
    return (
        <section className="grid min-w-0 grid-cols-1 gap-4">
            {processors.map((view) => (
                <ProcessorCard key={view.processor} view={view} />
            ))}
        </section>
    );
}
