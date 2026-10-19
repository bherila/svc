<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The tables of the database this test is connected to, and nothing else.
 *
 * `Schema::getTables()` and `Schema::getTableListing()` with no schema list
 * every non-system schema the connection can see. On SQLite that is `main`
 * plus anything attached; on MariaDB it is the whole server, and under
 * `--parallel` the server holds every ParaTest worker's
 * `svc_testing_test_<token>` schema and every `svc_probe_*` schema another
 * worker is migrating, rolling back or dropping at that moment.
 *
 * Reading one of those from inside this test's `RefreshDatabase` transaction
 * fails whenever another worker's DDL lands after the transaction's snapshot:
 * 1412 "Table definition has changed" when the table was created or altered,
 * 1146 "doesn't exist" when the probe was dropped between the listing and the
 * read (#475). The tables were never this test's to read, so the listing is
 * scoped to the connection's own schema rather than the read being retried.
 */
final class OwnTables
{
    /** @return list<string> bare table names, ordered as the engine lists them */
    public static function names(): array
    {
        return array_values(Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false));
    }
}
