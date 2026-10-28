<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Add SVC's contract link to the auth adapter's protected-resource metadata. */
final class PublishApiDocumentation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response instanceof JsonResponse && $response->getStatusCode() === 200) {
            $metadata = $response->getData(true);
            $metadata['service_documentation'] = rtrim((string) config('app.url'), '/').route('openapi.document', absolute: false);
            $response->setData($metadata);
        }

        return $response;
    }
}
