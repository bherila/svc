<?php

namespace App\Services\Mcp;

use Mcp\Capability\Discovery\SchemaValidator;
use Psr\Log\LoggerInterface;

/** Validates the original JSON shape before associative decoding erases it. */
final class AgentMcpSchemaValidator extends SchemaValidator
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AgentMcpRequestArguments $requestArguments,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param  array<string, mixed>|object  $schema
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        $wireArguments = $this->requestArguments->nextValidationArguments($data);

        return parent::validateAgainstJsonSchema($wireArguments ?? $data, $schema);
    }
}
