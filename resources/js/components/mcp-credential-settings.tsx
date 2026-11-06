import { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { apiRequest } from '@/lib/api';

export type McpCredentialRow = {
    id: string;
    name: string;
    show_all_tools: boolean;
    /** Finished by the server: the page assembles no URL. */
    update_href: string;
};

export type McpCredentials = {
    connections: McpCredentialRow[];
    tokens: McpCredentialRow[];
};

function CredentialRow({
    row,
    kind,
}: {
    row: McpCredentialRow;
    kind: 'connection' | 'token';
}) {
    const [on, setOn] = useState(row.show_all_tools);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');

    async function change(next: boolean) {
        setPending(true);
        setError('');

        const result = await apiRequest<{ show_all_tools: boolean }>(
            'PUT',
            row.update_href,
            { show_all_tools: next },
        );

        setPending(false);

        if (result.ok) {
            setOn(result.data.show_all_tools);
        } else {
            setError(result.message);
        }
    }

    return (
        <li>
            <Card className="min-w-0 ring-border">
                <CardContent className="grid min-w-0 grid-cols-1 gap-2">
                    <div className="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium wrap-anywhere">
                                {row.name}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {kind === 'connection'
                                    ? 'Connected app'
                                    : 'MCP token'}
                            </p>
                        </div>
                        <label className="flex min-h-8 shrink-0 items-center gap-2 text-sm pointer-coarse:min-h-11">
                            <Switch
                                aria-label={`Show all tools for ${row.name}`}
                                checked={on}
                                disabled={pending}
                                onCheckedChange={(next) => void change(next)}
                            />
                            Show all tools
                        </label>
                    </div>
                    {error !== '' && (
                        <p
                            role="alert"
                            className="text-sm wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                </CardContent>
            </Card>
        </li>
    );
}

/**
 * Each connected app and MCP token of the signed-in person, with its own
 * "Show all tools" switch. It is the fallback for assistants that cannot send
 * the `X-SVC-Toolsets: all` header or handle a changed tool list: with it on,
 * that one connection lists every tool it may use from the start. It grants
 * nothing and takes effect the next time the connection starts.
 */
export function McpCredentialSettings({
    credentials,
}: {
    credentials: McpCredentials;
}) {
    const empty =
        credentials.connections.length === 0 && credentials.tokens.length === 0;

    return (
        <section
            className="grid min-w-0 grid-cols-1 gap-3"
            data-test="mcp-credential-settings"
        >
            <h2 className="text-lg font-semibold">Your connections</h2>
            <p className="text-sm text-muted-foreground">
                By default an assistant starts with a short list of tools and
                asks for more as it needs them. Some assistants cannot do that.
                Turn on <strong>Show all tools</strong> for such a connection
                and it lists every tool you may use as soon as it starts. This
                grants nothing: every action is still checked against your
                permissions. It takes effect the next time the assistant
                connects, so reconnect it or start a new session; a session that
                is already open keeps the tool list it started with.
            </p>
            {empty ? (
                <p className="text-sm text-muted-foreground">
                    No connected apps or MCP tokens yet. Connect an assistant
                    above and it appears here.
                </p>
            ) : (
                <ul className="grid min-w-0 grid-cols-1 gap-3">
                    {credentials.connections.map((row) => (
                        <CredentialRow
                            key={`connection-${row.id}`}
                            row={row}
                            kind="connection"
                        />
                    ))}
                    {credentials.tokens.map((row) => (
                        <CredentialRow
                            key={`token-${row.id}`}
                            row={row}
                            kind="token"
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}
