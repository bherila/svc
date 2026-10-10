<?php

namespace App\Http\Controllers;

use App\Support\AgentApi\AgentOpenApi;
use Illuminate\Http\JsonResponse;

/**
 * The API contract, addressed to this installation (#384, #408).
 *
 * Generated from the operation registry, with its server and OAuth endpoints
 * taken from configuration, so a connector that imports the document from a
 * setup page reaches the installation it came from. Public, like the checked
 * copy in `public/openapi/`: a connector reads it before it has any credential.
 */
final class OpenApiDocumentController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(AgentOpenApi::forInstallation()->full(), 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES);
    }
}
