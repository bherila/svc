<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The API contract, addressed to this installation (#384).
 *
 * `public/openapi/svc-agent-v1.json` is the contract the tests and MCP tool
 * schemas are checked against, and it names one deployment. A connector that
 * imports the document from a setup page must reach the installation it came
 * from, so this serves the same document with its server and OAuth endpoints
 * taken from configuration. Public, like the file: a connector reads it before
 * it has any credential.
 */
final class OpenApiDocumentController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $base = rtrim((string) config('app.url'), '/');

        $document['servers'] = [['url' => $base.'/api/v1']];
        $flow = &$document['components']['securitySchemes']['oauth2']['flows']['authorizationCode'];
        $flow['authorizationUrl'] = (string) config('bherila-auth.oauth_server.authorization_endpoint', $base.'/oauth/authorize');
        $flow['tokenUrl'] = (string) config('bherila-auth.oauth_server.token_endpoint', $base.'/oauth/token');
        unset($flow);

        return response()->json($document, 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES);
    }
}
