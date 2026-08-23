<?php

namespace App\Services\AgentApi\Client;

final readonly class AgentApiTransportResponse
{
    /** @param array<string, mixed>|null $json */
    public function __construct(public int $status, public ?array $json) {}
}
