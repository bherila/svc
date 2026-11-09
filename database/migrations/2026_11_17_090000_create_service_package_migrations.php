<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving subscribers to a new package version (docs/service-packages.md,
 * slice 6: MIG-1 to MIG-5).
 *
 * - `service_package_migrations` is one scheduled move: the package, the
 *   version its subscribers are on and the one they move to, the effective
 *   date (the start of a billing period), the owner's message to clients,
 *   and its status (`scheduled`, `completed` once every target is settled,
 *   or `cancelled` before the date, MIG-5).
 * - `service_package_migration_targets` is each subscriber in it: the
 *   enrollment, the agreement it moves from and, once applied, the agreement
 *   that carries the new terms from the effective date. Its status
 *   (`scheduled`, `applied`, `skipped`, `cancelled`) and outcome code record
 *   what happened, or why it waits (MIG-3). The new price and the company's
 *   autopay limit at scheduling are kept for the flag MIG-4 raises.
 * - `service_package_enrollments.current_version_id` is the version whose
 *   terms the enrollment's agreement carries after a move; null until one
 *   applies, when the version signed (`accepted_version_id`, never changed)
 *   still is.
 *
 * Tenant-owned: composite keys keep every reference inside the row's
 * workspace (docs/client-management/tenant-foreign-keys.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_package_migrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('svpkm_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedBigInteger('from_version_id');
            $table->unsignedBigInteger('to_version_id');
            $table->date('effective_on');
            $table->string('message', 2000)->nullable();
            $table->string('status', 16);
            $table->unsignedBigInteger('scheduled_by_user_id')->nullable();
            $table->foreign('scheduled_by_user_id', 'svpkm_scheduler_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->foreign('cancelled_by_user_id', 'svpkm_canceller_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'svpkm_ws_id_unique');
            $table->index(['workspace_id', 'service_package_id', 'status'], 'svpkm_ws_package_status_idx');
            $table->index(['workspace_id', 'from_version_id'], 'svpkm_ws_from_version_idx');
            $table->index(['workspace_id', 'to_version_id'], 'svpkm_ws_to_version_idx');
            $table->foreign(['workspace_id', 'service_package_id'], 'svpkm_ws_package_fk')
                ->references(['workspace_id', 'id'])->on('service_packages')->restrictOnDelete();
            $table->foreign(['workspace_id', 'from_version_id'], 'svpkm_ws_from_version_fk')
                ->references(['workspace_id', 'id'])->on('service_package_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'to_version_id'], 'svpkm_ws_to_version_fk')
                ->references(['workspace_id', 'id'])->on('service_package_versions')->restrictOnDelete();
        });

        Schema::create('service_package_migration_targets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('svpkt_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_package_migration_id');
            $table->unsignedBigInteger('service_package_enrollment_id');
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('client_agreement_id');
            $table->unsignedBigInteger('successor_agreement_id')->nullable();
            $table->date('effective_on');
            $table->string('status', 16);
            $table->string('outcome_code', 64)->nullable();
            $table->string('outcome_detail', 1000)->nullable();
            $table->timestamp('outcome_recorded_at')->nullable();
            $table->integer('new_price_amount');
            $table->string('new_price_currency', 3);
            $table->integer('autopay_limit_amount')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'svpkt_ws_id_unique');
            $table->index(['status', 'id'], 'svpkt_status_idx');
            $table->index(['workspace_id', 'service_package_migration_id'], 'svpkt_ws_migration_idx');
            $table->index(['workspace_id', 'service_package_enrollment_id', 'status'], 'svpkt_ws_enrollment_status_idx');
            $table->index(['workspace_id', 'client_company_id'], 'svpkt_ws_company_idx');
            $table->index(['workspace_id', 'client_agreement_id'], 'svpkt_ws_agreement_idx');
            $table->index(['workspace_id', 'successor_agreement_id'], 'svpkt_ws_successor_idx');
            $table->foreign(['workspace_id', 'service_package_migration_id'], 'svpkt_ws_migration_fk')
                ->references(['workspace_id', 'id'])->on('service_package_migrations')->restrictOnDelete();
            $table->foreign(['workspace_id', 'service_package_enrollment_id'], 'svpkt_ws_enrollment_fk')
                ->references(['workspace_id', 'id'])->on('service_package_enrollments')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_company_id'], 'svpkt_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_agreement_id'], 'svpkt_ws_agreement_fk')
                ->references(['workspace_id', 'id'])->on('client_agreements')->restrictOnDelete();
            $table->foreign(['workspace_id', 'successor_agreement_id'], 'svpkt_ws_successor_fk')
                ->references(['workspace_id', 'id'])->on('client_agreements')->restrictOnDelete();
        });

        Schema::table('service_package_enrollments', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_version_id')->nullable()->after('accepted_version_id');
            $table->index(['workspace_id', 'current_version_id'], 'svpke_ws_current_version_idx');
            $table->foreign(['workspace_id', 'current_version_id'], 'svpke_ws_current_version_fk')
                ->references(['workspace_id', 'id'])->on('service_package_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        Schema::table('service_package_enrollments', function (Blueprint $table) use ($sqlite): void {
            $table->dropForeign($sqlite ? ['workspace_id', 'current_version_id'] : 'svpke_ws_current_version_fk');
            $table->dropIndex('svpke_ws_current_version_idx');
            $table->dropColumn('current_version_id');
        });
        Schema::dropIfExists('service_package_migration_targets');
        Schema::dropIfExists('service_package_migrations');
    }
};
