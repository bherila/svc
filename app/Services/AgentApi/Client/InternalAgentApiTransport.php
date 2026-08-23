<?php

namespace App\Services\AgentApi\Client;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Request as RequestFacade;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Executes the public REST routes without forwarding browser cookies. */
final class InternalAgentApiTransport
{
    public function __construct(
        private readonly Router $router,
        private readonly ExceptionHandler $exceptions,
        private readonly Request $outerRequest,
        private readonly Application $application,
    ) {}

    /** @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $json */
    public function send(string $method, string $path, array $query = [], ?array $json = null, ?string $idempotencyKey = null): AgentApiTransportResponse
    {
        $previous = $this->application->make('request');
        try {
            $request = Request::create('/api/v1/'.ltrim($path, '/'), strtoupper($method), array_filter($query, static fn (mixed $value): bool => $value !== null), [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => (string) $this->outerRequest->header('Authorization', ''),
                'REMOTE_ADDR' => (string) ($this->outerRequest->ip() ?? '127.0.0.1'),
            ], $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR));
            if ($json !== null) {
                $request->headers->set('Content-Type', 'application/json');
            }
            if ($idempotencyKey !== null) {
                $request->headers->set('Idempotency-Key', $idempotencyKey);
            }
            $request->setUserResolver(fn (?string $guard = null) => $this->outerRequest->user($guard));
            $this->application->instance('request', $request);
            RequestFacade::clearResolvedInstance();
            try {
                $response = $this->router->dispatch($request);
            } catch (Throwable $exception) {
                $response = $this->exceptions->render($request, $exception);
            }

            return new AgentApiTransportResponse($response->getStatusCode(), $this->decode($response));
        } finally {
            $this->application->instance('request', $previous);
            RequestFacade::clearResolvedInstance();
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(Response $response): ?array
    {
        $content = $response->getContent();
        if (! is_string($content) || trim($content) === '') {
            return null;
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
