<?php

namespace Tests\Feature\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\OpenApiHttpContract;
use Tests\TestCase;

final class OpenApiHttpContractTest extends TestCase
{
    public function test_refusals_are_validated_and_invalid_test_inputs_are_allowed_when_refused(): void
    {
        OpenApiHttpContract::assertExchange($this->exchange('PATCH', 'api/v1/workspaces/{workspace}/tasks/{task}', ['message' => 'Invalid task.'], 422, ['unexpected' => true]));
        OpenApiHttpContract::assertExchange($this->exchange('GET', 'api/v1/context', ['error' => 'invalid_token'], 401));
    }

    public function test_a_response_shape_that_disagrees_with_the_contract_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('context.get response 200 violates OpenAPI');
        OpenApiHttpContract::assertExchange($this->exchange('GET', 'api/v1/context', ['data' => []]));
    }

    public function test_an_undocumented_response_property_fails_even_on_a_refusal(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('context.get response 403 violates OpenAPI');
        OpenApiHttpContract::assertExchange($this->exchange('GET', 'api/v1/context', ['message' => 'Forbidden.', 'private_record' => 'synthetic'], 403));
    }

    public function test_a_live_api_route_missing_from_the_document_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('GET /undocumented is missing from OpenAPI');
        OpenApiHttpContract::assertExchange($this->exchange('GET', 'api/v1/undocumented', ['message' => 'Not found.'], 404));
    }

    public function test_a_successful_request_with_an_invalid_required_parameter_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('clients.list request path workspace violates OpenAPI');
        OpenApiHttpContract::assertExchange($this->exchange('GET', 'api/v1/workspaces/{workspace}/clients', ['data' => [], 'meta' => ['next_cursor' => null]]));
    }

    public function test_an_accepted_body_missing_a_required_field_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('tasks.update request body violates OpenAPI');
        OpenApiHttpContract::assertExchange($this->exchange('PATCH', 'api/v1/workspaces/{workspace}/tasks/{task}', ['data' => []], input: ['title' => 'Synthetic task'], validParameters: true));
    }

    public function test_wire_arrays_are_not_silently_turned_into_objects(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('context.get response 403 violates OpenAPI');
        OpenApiHttpContract::assertExchange($this->exchange('GET', 'api/v1/context', [], 403));
    }

    private function exchange(string $method, string $uri, array $body, int $status = 200, array $input = [], bool $validParameters = false): TestResponse
    {
        $path = str_replace(['{workspace}', '{task}'], $validParameters ? ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'] : ['invalid-workspace', 'synthetic-task'], $uri);
        $request = Request::create('/'.$path, $method, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'synthetic-contract-request'], content: json_encode((object) $input, JSON_THROW_ON_ERROR));
        $route = new Route($method, $uri, static fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        return TestResponse::fromBaseResponse(new JsonResponse($body, $status), $request);
    }
}
