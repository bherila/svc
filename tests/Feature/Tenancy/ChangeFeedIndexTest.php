<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The change feeds page by `updated_at desc, id desc` within one workspace,
 * so each table they read carries an index in exactly that order.
 */
final class ChangeFeedIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_change_feed_table_is_indexed_by_workspace_change_time_and_id(): void
    {
        foreach (['client_companies', 'client_invoices', 'client_invoice_payments'] as $table) {
            $indexes = collect(Schema::getIndexes($table))->keyBy('name');
            $name = $table.'_ws_updated_id_index';
            $this->assertTrue($indexes->has($name), $table);
            $this->assertSame(['workspace_id', 'updated_at', 'id'], $indexes[$name]['columns'], $table);
            $this->assertLessThanOrEqual(64, strlen($name));
        }
    }
}
