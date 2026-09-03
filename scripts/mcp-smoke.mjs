import process from 'node:process';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

/**
 * Authenticated MCP smoke, shared by the pre-merge lane and the post-deploy one.
 *
 * The two lanes differ only in what they are handed. Pre-merge runs against
 * `artisan serve` with seeded fixtures, so it also reads an agreement and proves
 * the data path end to end. Post-deploy runs against the real host with a
 * principal that is a member of nothing, so it proves the auth, session and
 * scope machinery and deliberately cannot read a business record - see
 * `DeploySmokeCredentialsCommand` for why that is the stronger guarantee.
 *
 * Everything here asserts shape, never content, and nothing prints a payload.
 */

const endpoint = process.env.MCP_SMOKE_URL;
const token = process.env.MCP_SMOKE_BEARER_TOKEN;
const wrongScopeToken = process.env.MCP_SMOKE_WRONG_SCOPE_BEARER_TOKEN;
const expectedResource = process.env.MCP_SMOKE_EXPECTED_RESOURCE;
const workspaceId = process.env.MCP_SMOKE_WORKSPACE_ID;
const agreementId = process.env.MCP_SMOKE_AGREEMENT_ID;
const expectDisabled = process.env.MCP_SMOKE_EXPECT_DISABLED === '1';

if (!endpoint || !token) {
    throw new Error('MCP smoke endpoint and credential are required.');
}

const connect = async (bearer, options = {}) => {
    const client = new Client({ name: 'svc-mcp-smoke', version: '1.0.0' });
    const transport = new StreamableHTTPClientTransport(new URL(endpoint), {
        requestInit: { headers: { Authorization: `Bearer ${bearer}` } },
        ...options,
    });
    await client.connect(transport);

    return { client, transport };
};

const passed = [];
const record = (step) => {
    passed.push(step);
    console.log(`  ok  ${step}`);
};

// The global kill switch is a transport-level refusal, not an empty MCP
// server. Check both the stable raw response and that the official client
// rejects initialization rather than accepting an invalid capabilities shape.
if (expectDisabled) {
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
            Accept: 'application/json, text/event-stream',
            'Mcp-Protocol-Version': '2025-06-18',
        },
        body: JSON.stringify({
            jsonrpc: '2.0',
            id: 1,
            method: 'initialize',
            params: {
                protocolVersion: '2025-06-18',
                capabilities: {},
                clientInfo: {
                    name: 'svc-mcp-disabled-smoke',
                    version: '1.0.0',
                },
            },
        }),
    });

    if (response.status !== 503) {
        throw new Error(
            `Globally disabled MCP returned ${response.status}, not 503.`,
        );
    }

    const body = await response.json();

    if (
        body.message !== 'The SVC MCP service is temporarily unavailable.' ||
        'result' in body
    ) {
        throw new Error(
            'Globally disabled MCP returned an unexpected response contract.',
        );
    }

    if (!(response.headers.get('cache-control') ?? '').includes('no-store')) {
        throw new Error('Globally disabled MCP response may be cached.');
    }

    record('globally disabled raw transport refusal');

    let connected = null;

    try {
        connected = await connect(token);
    } catch {
        record('official client rejects globally disabled initialization');
    }

    if (connected !== null) {
        await connected.transport.close().catch(() => {});

        throw new Error(
            'The official client connected while MCP was globally disabled.',
        );
    }

    console.log(`MCP disabled smoke passed (${passed.length} checks).`);
    process.exit(0);
}

// 1. Protected-resource discovery. The challenge has to name the metadata
//    document, and the document has to identify this API - a deployment serving
//    someone else's resource identifier would authenticate against the wrong
//    authorization server and is exactly the misconfiguration a local run
//    cannot see.
if (expectedResource) {
    const challenge = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize' }),
    });

    if (challenge.status !== 401) {
        throw new Error(
            `Unauthenticated MCP initialize returned ${challenge.status}, not a 401 challenge.`,
        );
    }

    const header = challenge.headers.get('www-authenticate') ?? '';
    const metadataUrl = header.match(/resource_metadata="([^"]+)"/)?.[1];

    if (!metadataUrl) {
        throw new Error(
            'The 401 challenge did not advertise a resource_metadata URL.',
        );
    }

    const metadata = await fetch(metadataUrl).then((response) => {
        if (!response.ok) {
            throw new Error(
                `The advertised resource metadata URL returned ${response.status}.`,
            );
        }

        return response.json();
    });

    if (metadata.resource !== expectedResource) {
        throw new Error(
            'The resource metadata identifies a different protected resource than expected.',
        );
    }

    record('protected-resource discovery');
}

const { client, transport } = await connect(token);

try {
    record('initialize');

    const tools = await client.listTools();
    const contextTool = tools.tools.find((tool) => tool.name === 'context.get');

    if (!contextTool) {
        throw new Error(
            'The authenticated MCP discovery response did not include context.get.',
        );
    }

    // The SDK validates a structured result against the tool's own output
    // schema - but only when one is advertised, so without this the validation
    // below would pass by being skipped rather than by succeeding.
    if (!contextTool.outputSchema) {
        throw new Error(
            'context.get advertises no output schema, so its structured result cannot be validated.',
        );
    }

    record('tools/list with an advertised output schema');

    const context = await client.callTool({
        name: 'context.get',
        arguments: {},
    });

    if (context.isError || !context.structuredContent) {
        throw new Error('context.get did not return structured content.');
    }

    record('authorized read, structured result validated against its schema');

    // 2. The data path, only where fixtures exist for it. Never on a deployed
    //    host: the post-deploy principal is a member of nothing, and that is
    //    the point rather than a limitation.
    if (workspaceId && agreementId) {
        const templates = await client.listResourceTemplates();
        const agreementTemplate =
            'svc://workspaces/{workspace_id}/agreements/{agreement_id}';

        if (
            !templates.resourceTemplates.some(
                (template) => template.uriTemplate === agreementTemplate,
            )
        ) {
            throw new Error(
                'The authenticated MCP discovery response did not include the agreement resource template.',
            );
        }

        const agreement = await client.readResource({
            uri: `svc://workspaces/${workspaceId}/agreements/${agreementId}`,
        });
        const agreementContent = agreement.contents[0];

        if (
            !agreementContent ||
            agreementContent.mimeType !== 'application/json' ||
            typeof agreementContent.text !== 'string'
        ) {
            throw new Error(
                'The agreement resource did not return JSON text content.',
            );
        }

        if (JSON.parse(agreementContent.text)?.data?.id !== agreementId) {
            throw new Error(
                'The agreement resource returned an unexpected canonical DTO.',
            );
        }

        record('agreement resource read');
    }

    // 3. A connection holding a different operation scope must still be refused
    //    this operation. The second credential deliberately carries
    //    `projects:read` rather than nothing, so the refusal proves operation
    //    scopes are independent instead of relying only on the empty discovery
    //    surface of a connection carrying `mcp:use` alone.
    if (wrongScopeToken) {
        const other = await connect(wrongScopeToken);

        try {
            const refused = await other.client
                .callTool({ name: 'context.get', arguments: {} })
                .catch((error) => ({ isError: true, error }));

            if (!refused.isError) {
                throw new Error(
                    'A connection lacking identity:read was allowed to read context.',
                );
            }

            record('a different operation scope does not authorize this one');
        } finally {
            await other.transport.close();
        }

        // 4. Session isolation. The first connection's session id, presented by
        //    the second credential, must not resume that session - otherwise a
        //    session id would be a bearer token in its own right.
        const stolen = transport.sessionId;

        if (!stolen) {
            throw new Error(
                'The authorized connection negotiated no session id to test isolation with.',
            );
        }

        let isolated = false;

        try {
            const hijack = await connect(wrongScopeToken, {
                sessionId: stolen,
            });
            await hijack.client.listTools();
            await hijack.transport.close();
        } catch {
            isolated = true;
        }

        if (!isolated) {
            throw new Error(
                "A second credential reused the first connection's MCP session id.",
            );
        }

        record('session isolation between two credentials');
    }

    await transport.terminateSession();
} finally {
    await transport.close().catch(() => {});
}

console.log(`MCP smoke passed (${passed.length} checks).`);
