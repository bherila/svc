<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_company_activity', function (Blueprint $table): void {
            $table->uuid('subject_public_id')->nullable()->after('external_subject_id');
            $table->string('deduplication_key', 64)->nullable()->after('subject_public_id');
            $table->index(
                ['workspace_id', 'subject_type', 'subject_public_id'],
                'cca_workspace_subject_public_idx',
            );
            $table->unique(
                ['workspace_id', 'deduplication_key'],
                'cca_workspace_deduplication_unique',
            );
        });

        Schema::table('client_stripe_payment_methods', function (Blueprint $table): void {
            $table->softDeletes();
        });

        // Provider webhooks arrive without tenant route parameters. This is a
        // system-level adapter index, not a tenant domain record: it remembers
        // only a one-way provider-ID hash, the resolved owner (when known), and
        // the latest attach/detach occurrence. The actual payment-method read is
        // still made through all three tenant predicates after this routing step.
        Schema::create('stripe_payment_method_states', function (Blueprint $table): void {
            $table->id();
            $table->char('provider_id_hash', 64)->unique();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_stripe_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 24)->default('unknown');
            $table->unsignedBigInteger('provider_created_at')->default(0);
            $table->string('stripe_event_id', 255)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'state']);
        });

        DB::table('client_stripe_payment_methods')
            ->select(['id', 'stripe_payment_method_id', 'workspace_id', 'client_company_id', 'client_stripe_customer_id'])
            ->orderBy('id')
            ->chunkById(500, function ($methods): void {
                $now = now();
                $rows = [];
                foreach ($methods as $method) {
                    $rows[] = [
                        'provider_id_hash' => hash('sha256', (string) $method->stripe_payment_method_id),
                        'workspace_id' => $method->workspace_id,
                        'client_company_id' => $method->client_company_id,
                        'client_stripe_customer_id' => $method->client_stripe_customer_id,
                        'state' => 'attached',
                        'provider_created_at' => 0,
                        'stripe_event_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('stripe_payment_method_states')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_payment_method_states');

        Schema::table('client_stripe_payment_methods', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('client_company_activity', function (Blueprint $table): void {
            $table->dropUnique('cca_workspace_deduplication_unique');
            $table->dropIndex('cca_workspace_subject_public_idx');
            $table->dropColumn(['subject_public_id', 'deduplication_key']);
        });
    }
};
