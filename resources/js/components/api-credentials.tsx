import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatTimestamp } from '@/lib/datetime';

export type ApiScope = { id: string; description: string };

export type ApiToken = {
    id: string;
    name: string;
    scopes: string[];
    created_at: string | null;
    expires_at: string | null;
    revoke_href: string;
};

export type OAuthApp = {
    id: string;
    name: string;
    confidential: boolean;
    redirect_uris: string[];
    scopes: string[];
    created_at: string | null;
    delete_href: string;
};

export type IssuedCredential =
    | { kind: 'token'; name: string; token: string }
    | {
          kind: 'app';
          name: string;
          client_id: string;
          client_secret: string | null;
      };

export type RestAccess = {
    api_base_url: string;
    openapi_url: string;
    authorize_url: string;
    token_url: string;
    scopes: ApiScope[];
    token_lifetimes: number[];
    issue_token_href: string;
    register_app_href: string;
    tokens: ApiToken[];
    apps: OAuthApp[];
    issued: IssuedCredential | null;
};

/**
 * Credentials for apps that use the REST API instead of MCP (#384).
 *
 * Two ways in, because connectors differ: some run the OAuth authorization-code
 * flow and need a registered app; others ask for "an API key" and take a
 * personal token. Both carry only the permissions chosen here, both stop at
 * the person's own role, and every secret is shown exactly once.
 */
export function ApiCredentials({
    rest,
    copy,
}: {
    rest: RestAccess;
    copy: (label: string, text: string) => React.ReactNode;
}) {
    return (
        <section className="grid min-w-0 grid-cols-1 gap-6">
            <div className="grid grid-cols-1 gap-3">
                <h2 className="text-lg font-semibold">
                    Other apps: REST API with OAuth or an API token
                </h2>
                <p className="text-sm text-muted-foreground">
                    Apps that do not speak MCP can use the same operations
                    through the REST API. Point them at the OpenAPI document,
                    then either register an OAuth app below (for apps that sign
                    you in) or create an API token (for apps that ask for a
                    key).
                </p>
                <div className="grid min-w-0 grid-cols-1 gap-3 lg:grid-cols-2">
                    {copy('OpenAPI document', rest.openapi_url)}
                    {copy('API base URL', rest.api_base_url)}
                    {copy('OAuth authorize URL', rest.authorize_url)}
                    {copy('OAuth token URL', rest.token_url)}
                </div>
            </div>

            {rest.issued !== null && (
                <IssuedNotice issued={rest.issued} copy={copy} />
            )}

            <div className="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-2">
                <TokenSection rest={rest} />
                <AppSection rest={rest} />
            </div>
        </section>
    );
}

function IssuedNotice({
    issued,
    copy,
}: {
    issued: IssuedCredential;
    copy: (label: string, text: string) => React.ReactNode;
}) {
    return (
        <div
            role="status"
            className="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-primary/40 bg-primary/5 p-5"
        >
            <p className="text-sm font-medium wrap-anywhere">
                {issued.kind === 'token'
                    ? `API token “${issued.name}” created.`
                    : `OAuth app “${issued.name}” registered.`}{' '}
                Copy it now: it will not be shown again.
            </p>
            {issued.kind === 'token' ? (
                copy('API token', issued.token)
            ) : (
                <>
                    {copy('Client ID', issued.client_id)}
                    {issued.client_secret !== null &&
                        copy('Client secret', issued.client_secret)}
                </>
            )}
        </div>
    );
}

function ScopePicker({
    scopes,
    selected,
    onChange,
    idPrefix,
}: {
    scopes: ApiScope[];
    selected: string[];
    onChange: (next: string[]) => void;
    idPrefix: string;
}) {
    return (
        <fieldset className="grid grid-cols-1 gap-2">
            <legend className="text-sm font-medium">Permissions</legend>
            {scopes.map((scope) => {
                const id = `${idPrefix}-${scope.id}`;

                return (
                    <label
                        key={scope.id}
                        htmlFor={id}
                        className="flex items-start gap-2 text-sm"
                    >
                        <input
                            id={id}
                            type="checkbox"
                            className="mt-1"
                            checked={selected.includes(scope.id)}
                            onChange={(event) =>
                                onChange(
                                    event.target.checked
                                        ? [...selected, scope.id]
                                        : selected.filter(
                                              (value) => value !== scope.id,
                                          ),
                                )
                            }
                        />
                        <span className="min-w-0 wrap-anywhere">
                            <code className="text-xs">{scope.id}</code>{' '}
                            <span className="text-muted-foreground">
                                {scope.description}
                            </span>
                        </span>
                    </label>
                );
            })}
        </fieldset>
    );
}

function TokenSection({ rest }: { rest: RestAccess }) {
    const [name, setName] = useState('');
    const [scopes, setScopes] = useState<string[]>([]);
    const [days, setDays] = useState(rest.token_lifetimes[0] ?? 30);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <section className="grid min-w-0 grid-cols-1 content-start gap-4 rounded-xl border border-border p-5">
            <h3 className="text-base font-semibold">API tokens</h3>
            <form
                className="grid grid-cols-1 gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    setBusy(true);
                    router.post(
                        rest.issue_token_href,
                        { name, scopes, days },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setName('');
                                setScopes([]);
                                setError(null);
                            },
                            onError: (errors) =>
                                setError(
                                    Object.values(errors)[0] ??
                                        'The token could not be created.',
                                ),
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            >
                <div className="grid grid-cols-1 gap-1.5">
                    <Label htmlFor="api-token-name">Token name</Label>
                    <Input
                        id="api-token-name"
                        value={name}
                        maxLength={120}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Which app uses it"
                    />
                </div>
                <ScopePicker
                    scopes={rest.scopes}
                    selected={scopes}
                    onChange={setScopes}
                    idPrefix="api-token-scope"
                />
                <fieldset className="grid grid-cols-1 gap-2">
                    <legend className="text-sm font-medium">
                        Expires after
                    </legend>
                    <div className="flex flex-wrap gap-4">
                        {rest.token_lifetimes.map((lifetime) => (
                            <label
                                key={lifetime}
                                className="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="radio"
                                    name="api-token-days"
                                    checked={days === lifetime}
                                    onChange={() => setDays(lifetime)}
                                />
                                {lifetime} days
                            </label>
                        ))}
                    </div>
                </fieldset>
                {error !== null && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <div>
                    <Button
                        type="submit"
                        size="sm"
                        disabled={
                            busy || name.trim() === '' || scopes.length === 0
                        }
                    >
                        Create API token
                    </Button>
                </div>
            </form>
            {rest.tokens.length > 0 && (
                <ul className="grid grid-cols-1 gap-3">
                    {rest.tokens.map((token) => (
                        <li
                            key={token.id}
                            className="grid min-w-0 grid-cols-1 gap-1 rounded-lg border border-border p-3 text-sm"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium wrap-anywhere">
                                    {token.name}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.delete(token.revoke_href, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Revoke
                                </Button>
                            </div>
                            <span className="text-xs wrap-anywhere text-muted-foreground">
                                {token.scopes.join(', ')}
                            </span>
                            {token.expires_at !== null && (
                                <span className="text-xs text-muted-foreground">
                                    Expires {formatTimestamp(token.expires_at)}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function AppSection({ rest }: { rest: RestAccess }) {
    const [name, setName] = useState('');
    const [redirects, setRedirects] = useState('');
    const [confidential, setConfidential] = useState(true);
    const [scopes, setScopes] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const redirectUris = redirects
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');

    return (
        <section className="grid min-w-0 grid-cols-1 content-start gap-4 rounded-xl border border-border p-5">
            <h3 className="text-base font-semibold">OAuth apps</h3>
            <form
                className="grid grid-cols-1 gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    setBusy(true);
                    router.post(
                        rest.register_app_href,
                        {
                            name,
                            redirect_uris: redirectUris,
                            confidential,
                            scopes,
                        },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setName('');
                                setRedirects('');
                                setScopes([]);
                                setError(null);
                            },
                            onError: (errors) =>
                                setError(
                                    Object.values(errors)[0] ??
                                        'The app could not be registered.',
                                ),
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            >
                <div className="grid grid-cols-1 gap-1.5">
                    <Label htmlFor="oauth-app-name">App name</Label>
                    <Input
                        id="oauth-app-name"
                        value={name}
                        maxLength={120}
                        onChange={(event) => setName(event.target.value)}
                    />
                </div>
                <div className="grid grid-cols-1 gap-1.5">
                    <Label htmlFor="oauth-app-redirects">
                        Redirect URIs (one per line)
                    </Label>
                    <Textarea
                        id="oauth-app-redirects"
                        value={redirects}
                        rows={3}
                        onChange={(event) => setRedirects(event.target.value)}
                        placeholder="https://app.example.test/oauth/callback"
                    />
                </div>
                <fieldset className="grid grid-cols-1 gap-2">
                    <legend className="text-sm font-medium">Client type</legend>
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="radio"
                            name="oauth-app-type"
                            className="mt-1"
                            checked={confidential}
                            onChange={() => setConfidential(true)}
                        />
                        <span>
                            Confidential: the app keeps a client secret on its
                            server
                        </span>
                    </label>
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="radio"
                            name="oauth-app-type"
                            className="mt-1"
                            checked={!confidential}
                            onChange={() => setConfidential(false)}
                        />
                        <span>Public: no secret, PKCE only</span>
                    </label>
                </fieldset>
                <ScopePicker
                    scopes={rest.scopes}
                    selected={scopes}
                    onChange={setScopes}
                    idPrefix="oauth-app-scope"
                />
                {error !== null && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <div>
                    <Button
                        type="submit"
                        size="sm"
                        disabled={
                            busy ||
                            name.trim() === '' ||
                            redirectUris.length === 0 ||
                            scopes.length === 0
                        }
                    >
                        Register OAuth app
                    </Button>
                </div>
            </form>
            {rest.apps.length > 0 && (
                <ul className="grid grid-cols-1 gap-3">
                    {rest.apps.map((app) => (
                        <li
                            key={app.id}
                            className="grid min-w-0 grid-cols-1 gap-1 rounded-lg border border-border p-3 text-sm"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium wrap-anywhere">
                                    {app.name}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.delete(app.delete_href, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Delete
                                </Button>
                            </div>
                            <span className="text-xs wrap-anywhere text-muted-foreground">
                                Client ID {app.id} ·{' '}
                                {app.confidential ? 'confidential' : 'public'}
                            </span>
                            <span className="text-xs wrap-anywhere text-muted-foreground">
                                {app.redirect_uris.join(', ')}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
