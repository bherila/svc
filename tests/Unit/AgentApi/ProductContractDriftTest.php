<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Square\SquarePresenter;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class ProductContractDriftTest extends TestCase
{
    public function test_documented_operations_exist_and_every_catalog_family_is_described(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $registry = (new AgentOperationCatalog)->registry();
            $contract = (string) file_get_contents(dirname(__DIR__, 3).'/docs/mcp-product-contract.md');
            // search is the catalog's single-word operation; family labels and fields are not operations.
            preg_match_all('/`(search|[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)`/', $contract, $matches);
            $this->assertNotEmpty($matches[1]);
            foreach (array_unique($matches[1]) as $name) {
                $this->assertNotNull($registry->find($name), "The product contract names missing operation {$name}.");
            }

            $families = [];
            foreach ($registry->all() as $operation) {
                $families[explode('.', $operation->id, 2)[0]] = true;
            }
            foreach (array_keys($families) as $family) {
                $this->assertMatchesRegularExpression('/(?<![a-z0-9_])'.preg_quote($family, '/').'(?![a-z0-9_])/', $contract, "The product contract does not describe catalog family {$family}.");
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    public function test_documented_card_summary_matches_the_allowlisted_square_projection(): void
    {
        $presenter = new SquarePresenter;
        $payment = $presenter->payment(['card_details' => ['card' => [
            'card_brand' => 'VISA', 'card_type' => 'CREDIT', 'last_4' => '1234',
            'card_number' => 'synthetic-forbidden-field', 'card_id' => 'synthetic-card-credential',
        ]]]);
        $this->assertSame([
            'status' => null, 'entry_method' => null,
            'card_brand' => 'VISA', 'card_type' => 'CREDIT', 'last_4' => '1234',
        ], $payment['card']);
        $invoice = $presenter->invoice(['payment_requests' => [['card_id' => 'synthetic-card-credential']]]);
        $this->assertTrue($invoice['payment_requests'][0]['card_on_file']);
        $this->assertArrayNotHasKey('card_id', $invoice['payment_requests'][0]);
        foreach (['mcp-product-contract.md', 'mcp.md'] as $document) {
            $contents = (string) file_get_contents(dirname(__DIR__, 3).'/docs/'.$document);
            foreach (['card_brand', 'card_type', 'last_4', 'card_on_file'] as $field) {
                $this->assertStringContainsString('`'.$field.'`', $contents, $document);
            }
        }
    }
}
