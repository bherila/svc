<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The billed-in-Square marker names the Square location of its series (#488).
 *
 * Square numbers invoices uniquely per location only, so one six-digit prefix
 * in two locations is two series. The marker becomes (location, prefix), and
 * one agreement per location may hold a prefix.
 *
 * Existing markers take the one location the mirror holds their series in,
 * when every live member names that same location. A marker whose series the
 * mirror holds in no location, or in more than one, or with a member that
 * names none, is left without a location: it still matches the prefix in
 * every location, as before, and the billing audit keeps reporting such a
 * prefix as `multiple_locations` until it is marked again with a location.
 * Nothing can create a marker without one any more, so the unique index no
 * longer needs to cover those rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exact = static function (ColumnDefinition $column): ColumnDefinition {
            return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? $column->collation('utf8mb4_bin')
                : $column;
        };
        Schema::table('client_agreements', function (Blueprint $table) use ($exact): void {
            $exact($table->string('square_location_id', 192)->nullable()->after('square_series_key'));
        });
        $this->backfill();

        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'square_location_id', 'square_series_key'], 'ca_ws_square_location_series_unique');
            $table->dropUnique('ca_ws_square_series_unique');
        });
    }

    /** Each marker naming no location takes its series' one location in its own workspace's mirror, if it has one. */
    public function backfill(): void
    {
        $marked = DB::table('client_agreements')->whereNotNull('square_series_key')->whereNull('square_location_id')
            ->orderBy('id')->get(['id', 'workspace_id', 'square_series_key']);
        foreach ($marked as $agreement) {
            $locations = DB::table('square_invoice_snapshots')
                ->where('workspace_id', $agreement->workspace_id)
                ->where('series_key', $agreement->square_series_key)
                ->whereNull('deleted_at')
                ->distinct()
                ->pluck('square_location_id')
                ->all();
            if (count($locations) === 1 && is_string($locations[0])) {
                DB::table('client_agreements')->where('id', $agreement->id)->update(['square_location_id' => $locations[0]]);
            }
        }
    }

    /** Refused while two agreements hold one prefix in different locations: the old index cannot hold them. */
    public function down(): void
    {
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'square_series_key'], 'ca_ws_square_series_unique');
            $table->dropUnique('ca_ws_square_location_series_unique');
            $table->dropColumn('square_location_id');
        });
    }
};
