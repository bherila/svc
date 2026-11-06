<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A hint kept by WorkspacePaymentConnections so a page can tell whether
        // to look at a workspace's Square connection without a query of its own.
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('has_square_connection')->default(false);
        });

        Schema::create('workspace_payment_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // `stripe` or `square`; see App\Support\Payments\PaymentProcessor.
            $table->string('processor', 16);
            // Stripe `acct_...`, or the Square merchant id.
            $table->string('external_account_id', 64);
            $table->string('display_name', 160)->nullable();
            // Stripe: `live` or `test`. Square: `production` or `sandbox`.
            $table->string('environment', 16)->nullable();
            // `entered`: keys typed in by an owner or admin of this workspace.
            // `reused`: borrowed from another workspace of the same owner.
            // `environment`: made once, by hand, from the deployment's
            // environment credentials (svc:payments:migrate-environment).
            $table->string('origin', 16);
            // The row a `reused` connection borrows from. Deliberately not a
            // foreign key: it names a row in ANOTHER workspace, which is the
            // one cross-tenant edge in the schema and is resolved only through
            // WorkspacePaymentConnections. A missing source reads as revoked.
            $table->unsignedBigInteger('reuses_connection_id')->nullable();
            // The opaque identifier in this connection's webhook URL. Random,
            // never sequential; a reused row has its own but receives nothing
            // there, its source's endpoint serves it.
            $table->char('endpoint_token', 40)->unique();
            // Stripe restricted key / Square access token, and the secret that
            // signs this workspace's webhooks. Encrypted by the model's casts.
            $table->text('secret')->nullable();
            $table->text('webhook_secret')->nullable();
            // What the browser may see of the key: `rk_live_...a1b2`.
            $table->string('secret_hint', 40)->nullable();
            // The Stripe webhook endpoint svc created (`we_...`), so it can be
            // deleted when the key is replaced or removed. Null when svc did not
            // create it (Square, or an endpoint registered by hand).
            $table->string('webhook_endpoint_id', 64)->nullable();
            // Square: the location payments are taken at.
            $table->string('location_id', 64)->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('connected_at');
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Holds the processor name while the row is active and is null once
            // revoked, so the unique index below allows exactly one active
            // connection per processor per workspace and any number of old ones.
            $table->string('active_slot', 16)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'active_slot'], 'workspace_payment_connection_active_unique');
            $table->index(['processor', 'external_account_id'], 'workspace_payment_connection_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_payment_connections');
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('has_square_connection');
        });
    }
};
