<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * svc's read-only mirror of the connected Square account (#438).
 *
 * Square stays the source of truth: these rows are a cache that the sync
 * refreshes, so explore, search and matching never wait on Square. Each
 * snapshot table is unique on (workspace, Square id), which is what keeps two
 * syncs running at once to one row per Square object - the sync inserts or
 * ignores, then updates only a row whose Square version is not newer than what
 * it fetched. Nothing here references an svc record, and nothing is sent to
 * Square.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Square ids are case-sensitive and opaque, so the unique keys compare
        // them byte for byte: under MariaDB's default collation `ABC` and `abc`
        // would be one row. SQLite already compares bytes.
        $exact = static function (ColumnDefinition $column): ColumnDefinition {
            return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? $column->collation('utf8mb4_bin')
                : $column;
        };

        Schema::create('square_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->string('status', 16);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users', 'id', 'sqsr_requested_by_fk')->nullOnDelete();
            // The Square-Version every request of the run sent.
            $table->string('api_version', 16);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('locations_seen')->default(0);
            $table->unsignedInteger('customers_seen')->default(0);
            $table->unsignedInteger('customers_changed')->default(0);
            $table->unsignedInteger('invoices_seen')->default(0);
            $table->unsignedInteger('invoices_changed')->default(0);
            $table->unsignedInteger('payments_resolved')->default(0);
            // The newest Square updated_at the run saw: how far the mirror reaches.
            $table->timestamp('high_water_mark')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'finished_at'], 'sqsr_ws_status_idx');
        });

        Schema::create('square_location_snapshots', function (Blueprint $table) use ($exact): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $exact($table->string('square_location_id', 191));
            $table->string('name')->nullable();
            $table->string('business_name')->nullable();
            $table->string('status', 32)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->char('fingerprint', 64);
            $table->timestamp('synced_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'square_location_id'], 'sqls_ws_location_unique');
        });

        Schema::create('square_customer_snapshots', function (Blueprint $table) use ($exact): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $exact($table->string('square_customer_id', 191));
            $table->unsignedBigInteger('square_version')->nullable();
            $table->string('given_name')->nullable();
            $table->string('family_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email_address')->nullable();
            $table->string('phone_number', 64)->nullable();
            $table->string('reference_id')->nullable();
            $table->timestamp('square_created_at')->nullable();
            $table->timestamp('square_updated_at')->nullable();
            $table->char('fingerprint', 64);
            $table->timestamp('synced_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'square_customer_id'], 'sqcs_ws_customer_unique');
            $table->index(['workspace_id', 'email_address'], 'sqcs_ws_email_idx');
        });

        Schema::create('square_invoice_snapshots', function (Blueprint $table) use ($exact): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $exact($table->string('square_invoice_id', 191));
            $table->unsignedBigInteger('square_version')->nullable();
            $exact($table->string('square_location_id', 191)->nullable());
            $exact($table->string('square_order_id', 191)->nullable());
            $exact($table->string('square_customer_id', 191)->nullable());
            $exact($table->string('square_subscription_id', 191)->nullable());
            $table->string('invoice_number', 191)->nullable();
            // The six-digit prefix of a dashboard series' NNNNNN-R-NNNN numbers.
            $table->char('series_key', 6)->nullable();
            $table->unsignedInteger('series_occurrence')->nullable();
            $table->string('title')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('status', 32);
            $table->char('currency', 3)->nullable();
            // Minor units, summed over the invoice's payment requests.
            $table->bigInteger('total_amount')->nullable();
            $table->bigInteger('paid_amount')->nullable();
            $table->bigInteger('next_payment_amount')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->date('sale_or_service_date')->nullable();
            $table->timestamp('square_created_at')->nullable();
            $table->timestamp('square_updated_at')->nullable();
            // Square's hosted view-and-pay page; only ever HTTPS on a Square host.
            $table->text('public_url')->nullable();
            $table->boolean('autopay')->default(false);
            // The allow-listed payment requests, with Square's reminder schedule.
            $table->json('payment_requests');
            // Square payment ids from the invoice's order, resolved for the
            // status and paid amount recorded in payment_ids_basis.
            $table->json('payment_ids')->nullable();
            $table->string('payment_ids_basis', 64)->nullable();
            $table->char('fingerprint', 64);
            $table->timestamp('synced_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'square_invoice_id'], 'sqis_ws_invoice_unique');
            $table->index(['workspace_id', 'series_key', 'series_occurrence'], 'sqis_ws_series_idx');
            $table->index(['workspace_id', 'square_customer_id'], 'sqis_ws_customer_idx');
            $table->index(['workspace_id', 'status'], 'sqis_ws_status_idx');
            $table->index(['workspace_id', 'due_on'], 'sqis_ws_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('square_invoice_snapshots');
        Schema::dropIfExists('square_customer_snapshots');
        Schema::dropIfExists('square_location_snapshots');
        Schema::dropIfExists('square_sync_runs');
    }
};
