import process from 'node:process';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const endpoint = process.env.MCP_SMOKE_URL;
const token = process.env.MCP_SMOKE_BEARER_TOKEN;
const workspaceId = process.env.MCP_SMOKE_WORKSPACE_ID;
const agreementId = process.env.MCP_SMOKE_AGREEMENT_ID;

if (!endpoint || !token || !workspaceId || !agreementId) {
    throw new Error('MCP smoke endpoint, credential, workspace, and agreement identifiers are required.');
}

const client = new Client({ name: 'svc-mcp-smoke', version: '1.0.0' });
const transport = new StreamableHTTPClientTransport(new URL(endpoint), {
    requestInit: { headers: { Authorization: `Bearer ${token}` } },
});
await client.connect(transport);

const tools = await client.listTools();

if (!tools.tools.some((tool) => tool.name === 'context.get')) {
    throw new Error('The authenticated MCP discovery response did not include context.get.');
}

const context = await client.callTool({ name: 'context.get', arguments: {} });

if (context.isError || !context.structuredContent) {
    throw new Error('context.get did not return structured content.');
}

const templates = await client.listResourceTemplates();
const agreementTemplate = 'svc://workspaces/{workspace_id}/agreements/{agreement_id}';

if (!templates.resourceTemplates.some((template) => template.uriTemplate === agreementTemplate)) {
    throw new Error('The authenticated MCP discovery response did not include the agreement resource template.');
}

const agreement = await client.readResource({
    uri: `svc://workspaces/${workspaceId}/agreements/${agreementId}`,
});
const agreementContent = agreement.contents[0];

if (!agreementContent || agreementContent.mimeType !== 'application/json' || typeof agreementContent.text !== 'string') {
    throw new Error('The agreement resource did not return JSON text content.');
}

const agreementPayload = JSON.parse(agreementContent.text);

if (agreementPayload?.data?.id !== agreementId) {
    throw new Error('The agreement resource returned an unexpected canonical DTO.');
}

await transport.terminateSession();

console.log('MCP smoke passed.');
