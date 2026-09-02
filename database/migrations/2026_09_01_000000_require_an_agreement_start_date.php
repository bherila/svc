<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settle what a null `client_agreements.starts_on` means by forbidding one (#147).
 *
 * The column had at least seven readings and several were incompatible. The
 * rate resolver and `ClientCompany::activeAgreement` treated a null as *in
 * force*; the timesheet's capacity query and every date-based selector treated
 * it as *excluded*; `BillingCycleResolver::cyclesForAgreement` threw. So an
 * undated agreement could stamp its hourly rate onto approved time while
 * contributing no capacity to the sheet that time was entered on, and while
 * being invisible to the selector that would have billed it.
 *
 * The decision recorded on #147 is that a null means *not yet in force*. This
 * migration takes that one step further and removes the state entirely, which
 * is the only version of the contract that cannot drift back apart: with no
 * null to read, seven readers cannot disagree about one.
 *
 * ## It refuses rather than invents
 *
 * There is no defensible date to backfill. A start date decides which cycles
 * exist, what capacity a period grants, and which agreement prices a given
 * day's work, so choosing one here would silently rewrite billing history for
 * whichever rows it touched. If any row is undated the migration stops and
 * names the count, leaving the dates to be established from the agreements
 * themselves.
 *
 * Both databases this has to cross were sized first, by the audit this replaces,
 * and both are already clean: 0 undated of 9 in production, 0 of 9 in the
 * source. So the constraint costs nothing to adopt here - it is a guard against
 * what could arrive next, not a repair of what is present.
 */
return new class extends Migration
{
    public function up(): void
    {
        $undated = DB::table('client_agreements')->whereNull('starts_on')->count();

        if ($undated > 0) {
            throw new RuntimeException(sprintf(
                '%d client agreement(s) have no start date, and there is no date this migration could supply '
                .'that would not rewrite what they billed. Find them with `SELECT id, status, billing_cadence '
                .'FROM client_agreements WHERE starts_on IS NULL`, set a start date on each from the agreement '
                .'itself, then migrate again.',
                $undated,
            ));
        }

        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->date('starts_on')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->date('starts_on')->nullable()->change();
        });
    }
};
