#!/usr/bin/env php
<?php

/**
 * Regenerate the code-owned dispute components, add `client_review` to the
 * row components that carry it, then the full operation document. The
 * components go in first because the operation catalog reads them from the
 * checked document while it builds.
 */
use App\Services\AgentApi\Operations\DisputeContract;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$path = __DIR__.'/../public/openapi/svc-agent-v1.json';
$document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
foreach (DisputeContract::components() as $component) {
    $document['components']['schemas'][$component] = DisputeContract::schema($component);
}
foreach (DisputeContract::reviewedRows() as $row) {
    $schema = $document['components']['schemas'][$row];
    $schema['properties']['client_review'] = ['$ref' => '#/components/schemas/ClientReview'];
    if (! in_array('client_review', $schema['required'], true)) {
        $schema['required'][] = 'client_review';
    }
    $document['components']['schemas'][$row] = $schema;
}
// The catalog finds each operation's request through its path, so a first run
// seeds the two settle operations; the full document below replaces them.
foreach ([
    '/workspaces/{workspace}/time-entries/{entry}/dispute/settle' => 'time_entries.settle_dispute',
    '/workspaces/{workspace}/expenses/{expense}/dispute/settle' => 'expenses.settle_dispute',
] as $route => $operationId) {
    $document['paths'][$route] ??= ['post' => [
        'operationId' => $operationId,
        'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DisputeSettleRequest']]]],
    ]];
}
OpenApiDocumentBuilder::write($document, $path);
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
AgentApiResponseSchemaCatalog::flush();
OpenApiDocumentBuilder::write(AgentOpenApi::checked()->full(), $path);
echo "Dispute components and OpenAPI regenerated.\n";
