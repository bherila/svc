<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\PublishApiDocumentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class PublishApiDocumentationTest extends TestCase
{
    public function test_metadata_retains_its_resource_and_headers_and_adds_the_document_url(): void
    {
        config(['app.url' => 'https://svc.example.test/']);
        $response = new JsonResponse(['resource' => 'https://svc.example.test/api/v1'], 200, ['Cache-Control' => 'public, max-age=300']);
        $published = (new PublishApiDocumentation)->handle(Request::create('/metadata'), static fn () => $response);

        $this->assertSame($response, $published);
        $this->assertSame([
            'resource' => 'https://svc.example.test/api/v1',
            'service_documentation' => 'https://svc.example.test/api/openapi.json',
        ], $published->getData(true));
        $this->assertSame('max-age=300, public', $published->headers->get('Cache-Control'));
    }

    public function test_refused_or_non_json_metadata_is_preserved(): void
    {
        foreach ([new JsonResponse(['error' => 'not_found'], 404), new JsonResponse(null, 204), new Response('Unavailable', 503)] as $response) {
            $before = $response->getContent();
            $published = (new PublishApiDocumentation)->handle(Request::create('/metadata'), static fn () => $response);

            $this->assertSame($response, $published);
            $this->assertSame($before, $published->getContent());
        }
    }
}
