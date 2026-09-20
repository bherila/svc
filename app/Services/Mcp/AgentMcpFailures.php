<?php

namespace App\Services\Mcp;

use App\Services\Engagement\EngagementException;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Turns a write's refusal into something the caller can act on.
 *
 * The MCP request handler collapses every exception it does not recognise into
 * a generic "temporarily unavailable", which is right for a bug and useless for
 * "this agreement cannot end before it starts". Only the refusals whose text was
 * written for a reader are passed through; anything else is left to that generic
 * answer so no internal detail leaks.
 */
final class AgentMcpFailures
{
    /**
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult
     */
    public static function translate(callable $work): mixed
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            throw new ToolCallException('The request was not valid: '.implode(' ', $exception->validator->errors()->all()));
        } catch (EngagementException|DomainException $exception) {
            throw new ToolCallException($exception->getMessage());
        } catch (ModelNotFoundException) {
            throw new ToolCallException('The requested record was not found in this workspace.');
        } catch (HttpExceptionInterface $exception) {
            throw match ($exception->getStatusCode()) {
                403 => new ToolCallException('This connection lacks the required permission.'),
                404 => new ToolCallException('The requested record was not found in this workspace.'),
                409 => new ToolCallException($exception->getMessage() !== '' ? $exception->getMessage() : 'The request conflicts with the current state.'),
                default => $exception,
            };
        }
    }
}
