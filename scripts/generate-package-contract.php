#!/usr/bin/env php
<?php

/**
 * Regenerate the code-owned service package components, then the full
 * operation document. The components go in first because the operation
 * catalog reads them from the checked document while it builds. The checked
 * document carries the package paths whatever the local configuration; the
 * document an installation serves leaves them out while the feature is off
 * (API-6).
 */
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$path = __DIR__.'/../public/openapi/svc-agent-v1.json';
$document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
foreach (ServicePackageOperations::components() as $component) {
    $document['components']['schemas'][$component] = ServicePackageOperations::schema($component);
}
OpenApiDocumentBuilder::write($document, $path);
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
AgentApiResponseSchemaCatalog::flush();
OpenApiDocumentBuilder::write(AgentOpenApi::checked()->full(), $path);
echo "Service package components and OpenAPI regenerated.\n";
