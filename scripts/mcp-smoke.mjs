import process from 'node:process';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const endpoint = process.env.MCP_SMOKE_URL;
const token = process.env.MCP_SMOKE_BEARER_TOKEN;

if (!endpoint || !token) {
    throw new Error('MCP_SMOKE_URL and MCP_SMOKE_BEARER_TOKEN are required.');
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

await transport.terminateSession();

console.log('MCP smoke passed.');
