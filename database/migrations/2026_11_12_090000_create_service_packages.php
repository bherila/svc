<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service packages (docs/service-packages.md): fixed bundles of agreement terms
 * an owner or admin publishes for clients to request or add.
 *
 * - `service_packages` is the stable identity: its link name, status, catalog
 *   listing and the version new sign-ups get. `published_version_id` points at
 *   one of its own versions through a composite key, so it can never name
 *   another tenant's version.
 * - `service_package_versions` holds every term. A version is a draft until it
 *   is published, and is never changed afterwards (PKG-2). At most one draft per
 *   package: `draft_marker` is 1 on the draft and NULL otherwise, which lets a
 *   unique key hold "one draft" without a partial index MariaDB does not have.
 *   Term columns share their names and units with `client_agreements`, because
 *   acceptance copies them there (PKG-7).
 * - `service_package_components` are a version's priced lines.
 * - `workspaces.package_catalog_token` is the unguessable public address of a
 *   workspace's catalog (PUB-2); NULL until a package is first published.
 *
 * Schema only; nothing here writes data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('package_catalog_token', 32)->nullable()->unique('ws_package_catalog_token_unique');
        });

        Schema::create('service_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('svpkg_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('link_name', 64);
            $table->string('status', 16);
            $table->boolean('is_listed')->default(false);
            $table->boolean('portal_addable')->default(true);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id', 'svpkg_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'svpkg_ws_id_unique');
            $table->unique(['workspace_id', 'link_name'], 'svpkg_ws_link_unique');
            $table->index(['workspace_id', 'status'], 'svpkg_ws_status_idx');
        });

        Schema::create('service_package_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('svpkv_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedInteger('number');
            $table->unsignedTinyInteger('draft_marker')->nullable();
            // What the public page shows.
            $table->string('name', 200);
            $table->string('summary', 300)->nullable();
            $table->text('body_markdown')->nullable();
            // Agreement terms, named and sized as on client_agreements.
            $table->text('agreement_text')->nullable();
            $table->char('currency', 3);
            $table->string('billing_cadence', 20);
            $table->unsignedBigInteger('hourly_rate_amount')->nullable();
            $table->unsignedBigInteger('retainer_amount')->nullable();
            $table->unsignedInteger('retainer_minutes')->nullable();
            $table->unsignedSmallInteger('rollover_months')->nullable();
            $table->string('first_cycle_proration', 32)->nullable();
            // What sign-up asks for.
            $table->boolean('charge_setup_fee_at_signup')->default(false);
            $table->boolean('charge_first_period')->default(false);
            $table->boolean('require_autopay')->default(false);
            $table->unsignedBigInteger('autopay_limit_amount')->nullable();
            $table->boolean('pay_before_activation')->default(false);
            $table->unsignedSmallInteger('payment_lapse_days')->default(14);
            $table->string('activation_mode', 16);
            $table->string('invoice_issuing', 16);
            // Cancellation terms, copied onto the agreement.
            $table->unsignedSmallInteger('notice_days')->default(0);
            $table->unsignedSmallInteger('minimum_term_months')->nullable();
            $table->string('cancellation_mode', 16);
            $table->json('intake_questions')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->foreign('published_by_user_id', 'svpkv_publisher_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'svpkv_ws_id_unique');
            $table->unique(['workspace_id', 'service_package_id', 'number'], 'svpkv_number_unique');
            $table->unique(['workspace_id', 'service_package_id', 'draft_marker'], 'svpkv_one_draft_unique');
            $table->foreign(['workspace_id', 'service_package_id'], 'svpkv_ws_package_fk')
                ->references(['workspace_id', 'id'])->on('service_packages')->cascadeOnDelete();
        });

        Schema::table('service_packages', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'published_version_id'], 'svpkg_ws_published_version_fk')
                ->references(['workspace_id', 'id'])->on('service_package_versions')->restrictOnDelete();
        });

        Schema::create('service_package_components', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('svpkc_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_package_version_id');
            $table->string('kind', 16);
            $table->string('description', 500);
            $table->string('cadence', 20);
            $table->unsignedBigInteger('amount');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->boolean('is_taxable')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'svpkc_ws_id_unique');
            $table->foreign(['workspace_id', 'service_package_version_id'], 'svpkc_ws_version_fk')
                ->references(['workspace_id', 'id'])->on('service_package_versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_components');
        Schema::table('service_packages', function (Blueprint $table): void {
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? ['workspace_id', 'published_version_id'] : 'svpkg_ws_published_version_fk');
        });
        Schema::dropIfExists('service_package_versions');
        Schema::dropIfExists('service_packages');
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropUnique('ws_package_catalog_token_unique');
            $table->dropColumn('package_catalog_token');
        });
    }
};
