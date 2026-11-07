#!/usr/bin/env php
<?php

/**
 * Regenerate the code-owned autopay components, then the full operation
 * document. The components go in first because the operation catalog reads them
 * from the checked document while it builds.
 */
use App\Services\AgentApi\Operations\AutopayContract;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$path = __DIR__.'/../public/openapi/svc-agent-v1.json';
$document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
foreach (AutopayContract::components() as $component) {
    $document['components']['schemas'][$component] = AutopayContract::schema($component);
}
OpenApiDocumentBuilder::write($document, $path);
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
AgentApiResponseSchemaCatalog::flush();
OpenApiDocumentBuilder::write(AgentOpenApi::checked()->full(), $path);
echo "Autopay components and OpenAPI regenerated.\n";
