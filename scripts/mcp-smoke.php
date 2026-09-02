<?php

declare(strict_types=1);

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Enum\ProtocolVersion;

require __DIR__.'/../vendor/autoload.php';

$endpoint = getenv('MCP_SMOKE_URL');
$token = getenv('MCP_SMOKE_BEARER_TOKEN');
if (! is_string($endpoint) || $endpoint === '' || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "MCP_SMOKE_URL must be an absolute MCP endpoint URL.\n");
    exit(2);
}
if (! is_string($token) || $token === '') {
    fwrite(STDERR, "MCP_SMOKE_BEARER_TOKEN must contain a short-lived MCP bearer token.\n");
    exit(2);
}

$client = Client::builder()
    ->setClientInfo('svc-mcp-smoke', '1.0.0')
    ->setProtocolVersion(ProtocolVersion::V2025_06_18)
    ->setInitTimeout(15)
    ->setRequestTimeout(15)
    ->setMaxRetries(0)
    ->build();
$client->connect(new HttpTransport($endpoint, ['Authorization' => 'Bearer '.$token]));

$tools = $client->listTools();
$names = array_map(static fn ($tool): string => $tool->name, $tools->tools);
if (! in_array('context.get', $names, true)) {
    fwrite(STDERR, "The authenticated MCP discovery response did not include context.get.\n");
    exit(1);
}

$context = $client->callTool('context.get');
if ($context->isError || $context->structuredContent === null) {
    fwrite(STDERR, "context.get did not return structured content.\n");
    exit(1);
}

$client->disconnect();
fwrite(STDOUT, "MCP smoke passed.\n");
