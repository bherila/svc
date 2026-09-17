import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

interface McpSetupProps {
    serverUrl: string;
    available: boolean;
}

function CopyBlock({ label, text }: { label: string; text: string }) {
    const [message, setMessage] = useState('');

    async function copy() {
        try {
            if (!navigator.clipboard?.writeText) {
                setMessage('Select the text below and copy it manually.');

                return;
            }

            await navigator.clipboard.writeText(text);
            setMessage('Copied');
        } catch {
            setMessage('Select the text below and copy it manually.');
        }
    }

    return (
        <div className="min-w-0 rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-medium">{label}</span>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={copy}
                    aria-label={`Copy ${label}`}
                    data-test={`mcp-copy-${label.toLowerCase().replaceAll(' ', '-')}`}
                >
                    Copy
                </Button>
            </div>
            <pre className="mt-3 text-sm wrap-anywhere whitespace-pre-wrap">
                <code>{text}</code>
            </pre>
            <p role="status" className="mt-2 text-xs text-muted-foreground">
                {message}
            </p>
        </div>
    );
}

export default function McpSetup({ serverUrl, available }: McpSetupProps) {
    return (
        <WorkspaceShell>
            <Head title="MCP setup guide" />
            <main className={cn(SHELL_CONTAINER, 'py-8')}>
                <div className="grid min-w-0 grid-cols-1 gap-8">
                    <section className="grid grid-cols-1 gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Connect your AI assistant to SVC
                        </h1>
                        <p className="text-muted-foreground">
                            MCP (Model Context Protocol) lets your assistant
                            work with SVC projects, tasks, time and billing
                            using your account’s permissions. Choose your
                            assistant below, connect once, then ask in plain
                            language.
                        </p>
                        {!available && (
                            <p
                                role="alert"
                                className="rounded-lg border border-border p-4"
                            >
                                MCP connections are currently unavailable on
                                this installation. Ask your workspace
                                administrator before continuing.
                            </p>
                        )}
                        <CopyBlock label="Server URL" text={serverUrl} />
                        <p className="text-sm text-muted-foreground">
                            Connection type: Streamable HTTP. Authentication:
                            OAuth. Sign in with the same account you use for SVC
                            and review the requested permissions. No API key or
                            password needs to be pasted into your assistant.
                        </p>
                    </section>
                    <div className="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-3">
                        <section className="grid min-w-0 grid-cols-1 content-start gap-4 rounded-xl border border-border p-5">
                            <h2 className="text-lg font-semibold">ChatGPT</h2>
                            <ol className="list-decimal space-y-3 pl-5 text-sm">
                                <li>
                                    Open ChatGPT on the web. In Settings, open
                                    Security and login and enable Developer mode
                                    if your account permits it. Then open Apps.
                                </li>
                                <li>
                                    Create a custom MCP app named SVC. Paste the
                                    Server URL above and choose OAuth
                                    authentication.
                                </li>
                                <li>
                                    Connect, sign in and approve the permissions
                                    you want to grant.
                                </li>
                                <li>
                                    Select SVC from the apps/tools menu in a new
                                    conversation and try the connection prompt
                                    below.
                                </li>
                            </ol>
                            <p className="text-sm text-muted-foreground">
                                Custom apps depend on your plan and workspace
                                settings. If these controls are missing, ask
                                your ChatGPT administrator.
                            </p>
                            <a
                                className="text-sm underline"
                                href="https://developers.openai.com/api/docs/guides/developer-mode"
                            >
                                ChatGPT setup documentation
                            </a>
                        </section>
                        <section className="grid min-w-0 grid-cols-1 content-start gap-4 rounded-xl border border-border p-5">
                            <h2 className="text-lg font-semibold">Claude</h2>
                            <ol className="list-decimal space-y-3 pl-5 text-sm">
                                <li>
                                    In Claude’s Settings, open Connectors and
                                    add a custom connector named SVC.
                                </li>
                                <li>
                                    Paste the Server URL, connect, then complete
                                    sign-in and consent.
                                </li>
                                <li>
                                    Enable SVC in your conversation and try the
                                    connection prompt below.
                                </li>
                            </ol>
                            <p className="text-sm text-muted-foreground">
                                Connector availability depends on your plan and
                                administrator. For Claude Code, run this
                                command, then use /mcp to authenticate:
                            </p>
                            <CopyBlock
                                label="Claude Code command"
                                text={`claude mcp add --transport http svc ${serverUrl}`}
                            />
                            <a
                                className="text-sm underline"
                                href="https://code.claude.com/docs/en/mcp"
                            >
                                Claude Code setup documentation
                            </a>
                        </section>
                        <section className="grid min-w-0 grid-cols-1 content-start gap-4 rounded-xl border border-border p-5">
                            <h2 className="text-lg font-semibold">Codex</h2>
                            <p className="text-sm">
                                In the Codex CLI, add the server and sign in
                                using the two commands below. Complete the
                                browser consent flow, then start a new Codex
                                session.
                            </p>
                            <CopyBlock
                                label="Codex commands"
                                text={`codex mcp add svc --url ${serverUrl}\ncodex mcp login svc`}
                            />
                            <p className="text-sm text-muted-foreground">
                                If your Codex app offers MCP settings, add the
                                same Server URL there and authenticate with
                                OAuth.
                            </p>
                            <a
                                className="text-sm underline"
                                href="https://developers.openai.com/codex/mcp"
                            >
                                Codex setup documentation
                            </a>
                        </section>
                    </div>
                    <section className="grid grid-cols-1 gap-4">
                        <h2 className="text-lg font-semibold">
                            Try your first request
                        </h2>
                        <CopyBlock
                            label="Connection prompt"
                            text="Use SVC to show which workspaces and projects I can access. Ask me which workspace to use before making changes."
                        />
                        <ul className="list-disc space-y-2 pl-5 text-sm">
                            <li>
                                “Show my open tasks for the Sample Project.”
                            </li>
                            <li>
                                “Log 30 minutes on Sample Project for September
                                16, 2026: setup investigation. Keep it as a
                                draft.”
                            </li>
                            <li>“Show my time entries for this week.”</li>
                            <li>
                                “List invoices I can view and give me their SVC
                                links.”
                            </li>
                        </ul>
                        <p className="text-sm text-muted-foreground">
                            Use the actual project name and date. Logging time
                            and changing tasks require write permission;
                            approving time is a separate permission and request.
                            The assistant only sees tools enabled for your
                            account. Client portal access stays limited to the
                            client and projects you can already view. Payments,
                            when available, record money already received; use
                            invoice links to pay. MCP does not upload files.
                        </p>
                    </section>
                    <section className="grid grid-cols-1 gap-4">
                        <h2 className="text-lg font-semibold">
                            Troubleshooting and disconnecting
                        </h2>
                        <dl className="grid grid-cols-1 gap-4 text-sm">
                            <div>
                                <dt className="font-medium">
                                    Sign-in expired or authorization failed
                                </dt>
                                <dd className="mt-1 text-muted-foreground">
                                    Reconnect in your assistant and complete
                                    OAuth sign-in again. Check that you used the
                                    full Server URL and the correct SVC account.
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">
                                    A project or tool is missing
                                </dt>
                                <dd className="mt-1 text-muted-foreground">
                                    Check your access in SVC. Ask the assistant
                                    to refresh its tools and list your
                                    workspaces. Your SVC role, consent
                                    permissions and the installation’s enabled
                                    features all affect access.
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">
                                    Opening the URL in a browser does not show a
                                    page
                                </dt>
                                <dd className="mt-1 text-muted-foreground">
                                    That is expected: the Server URL is an MCP
                                    endpoint. Paste it into your assistant’s
                                    connector settings instead.
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">
                                    The service is temporarily unavailable
                                </dt>
                                <dd className="mt-1 text-muted-foreground">
                                    Wait and retry, or contact your
                                    administrator. For an uncertain write
                                    result, ask the assistant to check existing
                                    entries before repeating it.
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">
                                    Stop using SVC with an assistant
                                </dt>
                                <dd className="mt-1 text-muted-foreground">
                                    Disconnect or remove SVC in the assistant’s
                                    connector settings. Removing the connector
                                    stops its use there; it does not delete SVC
                                    records.
                                </dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </main>
        </WorkspaceShell>
    );
}
