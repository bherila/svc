<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acceptance and activation facts on an enrollment (docs/service-packages.md,
 * slice 3a: ACC-2, ACC-5).
 *
 * - `accepted_at`, `accepted_by_user_id`, `signer_name`, `signer_title` and
 *   `accepted_version_id` are written once, when a company contact accepts,
 *   and never again (ACC-2). `accepted_version_id` is the exact version the
 *   contact signed; `service_package_version_id` stays the version that was
 *   offered, so the two differ exactly when a newer version was published in
 *   between (EDGE-7).
 * - `activated_at` and `activated_by_user_id` record when the service started,
 *   and which owner or admin started it (null when it started on its own).
 *
 * Schema only; nothing here writes data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_package_enrollments', function (Blueprint $table): void {
            $table->timestamp('accepted_at')->nullable()->after('offered_at');
            $table->unsignedBigInteger('accepted_by_user_id')->nullable()->after('accepted_at');
            $table->foreign('accepted_by_user_id', 'svpke_acceptor_fk')->references('id')->on('users')->nullOnDelete();
            $table->string('signer_name', 200)->nullable()->after('accepted_by_user_id');
            $table->string('signer_title', 200)->nullable()->after('signer_name');
            $table->unsignedBigInteger('accepted_version_id')->nullable()->after('signer_title');
            // Named, so the rollback can drop it: InnoDB does not drop the index
            // it would otherwise create to serve the key below.
            $table->index(['workspace_id', 'accepted_version_id'], 'svpke_ws_accepted_version_idx');
            $table->foreign(['workspace_id', 'accepted_version_id'], 'svpke_ws_accepted_version_fk')
                ->references(['workspace_id', 'id'])->on('service_package_versions')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable()->after('accepted_version_id');
            $table->unsignedBigInteger('activated_by_user_id')->nullable()->after('activated_at');
            $table->foreign('activated_by_user_id', 'svpke_activator_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'id'], 'svpke_ws_status_idx');
            $table->index(['workspace_id', 'client_company_id', 'service_package_id', 'status'], 'svpke_ws_company_package_idx');
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        Schema::table('service_package_enrollments', function (Blueprint $table) use ($sqlite): void {
            $table->dropForeign($sqlite ? ['workspace_id', 'accepted_version_id'] : 'svpke_ws_accepted_version_fk');
            $table->dropForeign($sqlite ? ['accepted_by_user_id'] : 'svpke_acceptor_fk');
            $table->dropForeign($sqlite ? ['activated_by_user_id'] : 'svpke_activator_fk');
            $table->dropIndex('svpke_ws_accepted_version_idx');
            $table->dropIndex('svpke_ws_status_idx');
            $table->dropIndex('svpke_ws_company_package_idx');
            $table->dropColumn(['accepted_at', 'accepted_by_user_id', 'signer_name', 'signer_title', 'accepted_version_id', 'activated_at', 'activated_by_user_id']);
        });
    }
};
