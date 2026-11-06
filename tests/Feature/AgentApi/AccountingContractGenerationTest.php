<?php

namespace Tests\Feature\AgentApi;

use App\Services\AgentApi\Operations\AccountingOperations;
use App\Support\AgentApi\AgentOpenApi;
use Tests\TestCase;

final class AccountingContractGenerationTest extends TestCase
{
    public function test_accounting_components_match_their_code_owned_schemas(): void
    {
        $document = AgentOpenApi::checked()->full();
        foreach (AccountingOperations::COMPONENTS as $id => $component) {
            $this->assertSame(AccountingOperations::output($id), $document['components']['schemas'][$component], 'Run php scripts/generate-accounting-contract.php and pnpm run api:types');
        }
    }
}
