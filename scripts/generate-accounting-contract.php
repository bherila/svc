#!/usr/bin/env php
<?php

/** Regenerate code-owned accounting components, then the full operation document. */
use App\Services\AgentApi\Operations\AccountingOperations;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$path = __DIR__.'/../public/openapi/svc-agent-v1.json';
$document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
foreach (AccountingOperations::COMPONENTS as $id => $component) {
    $document['components']['schemas'][$component] = AccountingOperations::output($id);
}
OpenApiDocumentBuilder::write($document, $path);
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
AgentApiResponseSchemaCatalog::flush();
OpenApiDocumentBuilder::write(AgentOpenApi::checked()->full(), $path);
echo "Accounting components and OpenAPI regenerated.\n";
