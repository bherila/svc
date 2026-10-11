<?php

namespace Tests\Feature\Access;

use BWH\Auth\OAuth\DelegatedAccess\DatabaseNonceStore;
use BWH\Auth\OAuth\DelegatedAccess\DatabaseReceiptStore;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/** The delegated access tables land on the connections their stores use. */
final class DelegatedAccessTablesTest extends TestCase
{
    use UsesAProbeDatabase;

    /** Codex review on #422: the store reads the configured connection, so the migration must create it there. */
    public function test_the_nonce_and_receipt_tables_are_created_on_their_configured_connection(): void
    {
        $this->bootProbeDatabase('delegated_probe');
        config([
            'bherila-auth.delegated_access.nonce_connection' => 'delegated_probe',
            'bherila-auth.delegated_access.receipt_connection' => 'delegated_probe',
        ]);

        foreach (['2026_10_11_020000_create_delegated_access_nonces.php', '2026_10_11_020100_create_delegated_access_receipts.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        $this->assertTrue(Schema::connection('delegated_probe')->hasTable(DatabaseNonceStore::TABLE));
        $this->assertTrue(Schema::connection('delegated_probe')->hasTable(DatabaseReceiptStore::TABLE));
    }
}
