<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use App\Services\Square\SquareReadService;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thin MCP adapter over the read-only Square pass-through (#438). The REST
 * routes call the same {@see SquareReadService}, so both answer alike; a
 * refusal's text is already safe to show, and is passed through as is.
 */
final class AgentMcpSquareTools
{
    private const string ID = '^[A-Za-z0-9][A-Za-z0-9:_-]*$';

    public function __construct(
        private readonly SquareReadService $square,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function locations(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->locations($context->principal->subject, $context->workspace));
    }

    /** @return array<string, mixed> */
    public function customers(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->customers($context->principal->subject, $context->workspace, $limit, $cursor));
    }

    /** @return array<string, mixed> */
    public function searchCustomers(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 254)] ?string $email = null,
        #[Schema(minLength: 1, maxLength: 32)] ?string $phone = null,
        #[Schema(enum: ['exact', 'fuzzy'])] string $match = 'fuzzy',
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->searchCustomers($context->principal->subject, $context->workspace, $email, $phone, $match, $limit, $cursor));
    }

    /** @return array<string, mixed> */
    public function customer(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] string $customer_id,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->customer($context->principal->subject, $context->workspace, $customer_id));
    }

    /** @return array<string, mixed> */
    public function invoices(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] string $location_id,
        #[Schema(minimum: 1, maximum: 50)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->invoices($context->principal->subject, $context->workspace, $location_id, $limit, $cursor));
    }

    /** @return array<string, mixed> */
    public function searchInvoices(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] string $location_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] ?string $customer_id = null,
        #[Schema(enum: ['asc', 'desc'])] string $sort_order = 'desc',
        #[Schema(minimum: 1, maximum: 50)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->searchInvoices($context->principal->subject, $context->workspace, $location_id, $customer_id, $sort_order, $limit, $cursor));
    }

    /** @return array<string, mixed> */
    public function invoice(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] string $invoice_id,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->invoice($context->principal->subject, $context->workspace, $invoice_id));
    }

    /** @return array<string, mixed> */
    public function order(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] string $order_id,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->order($context->principal->subject, $context->workspace, $order_id));
    }

    /** @return array<string, mixed> */
    public function payments(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'date-time')] ?string $begin_time = null,
        #[Schema(format: 'date-time')] ?string $end_time = null,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] ?string $location_id = null,
        #[Schema(enum: ['asc', 'desc'])] string $sort_order = 'desc',
        #[Schema(minimum: 1, maximum: 50)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->payments($context->principal->subject, $context->workspace, $begin_time, $end_time, $location_id, $sort_order, $limit, $cursor));
    }

    /** @return array<string, mixed> */
    public function payment(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] string $payment_id,
    ): array {
        return $this->read($workspace_id, fn (McpRequestContext $context): array => $this->square->payment($context->principal->subject, $context->workspace, $payment_id));
    }

    /**
     * @param  callable(McpRequestContext): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function read(string $workspaceId, callable $read): array
    {
        $context = $this->requestContext ?? throw new LogicException('MCP Square tools require a request context.');
        if (! $context->principal->hasScope('billing:read')) {
            throw new ToolCallException('This connection lacks the required permission.');
        }
        $context = $this->accounts->resolve($context, $workspaceId);

        try {
            return $read($context);
        } catch (ValidationException $exception) {
            throw new ToolCallException('The request was not valid: '.implode(' ', $exception->validator->errors()->all()));
        } catch (HttpExceptionInterface $exception) {
            // Square refusals and svc's own (manager-only, not connected, rate
            // limit) are written for the reader and carry nothing private.
            throw new ToolCallException($exception->getMessage() !== '' ? $exception->getMessage() : 'Square could not be read.');
        }
    }
}
