<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-up requests and enrollments (docs/service-packages.md, slice 2).
 *
 * - `service_package_requests` is what the public form writes, and nothing
 *   else (PUB-4). It names the package and the version the visitor read.
 *   Visitor-supplied text lives in plain columns and `answers`; erasing a
 *   closed request (EDGE-10) clears them and stamps `erased_at`, leaving the
 *   package, dates and outcome. `email_hash` keeps the per-address cap working
 *   without a second copy of the address.
 * - `service_package_enrollments` is a client's subscription to a package: the
 *   company, its project, its agreement and the version it carries. Approval
 *   creates one as `offered`; acceptance, payment and activation (slice 3)
 *   move it on. At most one request leads to one enrollment.
 *
 * Schema only; nothing here writes data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_package_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('svpkr_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedBigInteger('service_package_version_id');
            $table->string('status', 16);
            $table->string('company_name', 200)->nullable();
            $table->string('contact_name', 200)->nullable();
            $table->string('email', 254)->nullable();
            $table->char('email_hash', 64);
            $table->string('phone', 50)->nullable();
            $table->json('answers')->nullable();
            $table->unsignedBigInteger('requester_user_id')->nullable();
            $table->foreign('requester_user_id', 'svpkr_requester_fk')->references('id')->on('users')->nullOnDelete();
            $table->char('client_fingerprint', 64)->nullable();
            $table->timestamp('submitted_at');
            $table->unsignedBigInteger('client_company_id')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->foreign('decided_by_user_id', 'svpkr_decider_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 1000)->nullable();
            $table->timestamp('erased_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'svpkr_ws_id_unique');
            $table->index(['workspace_id', 'status', 'id'], 'svpkr_ws_status_idx');
            $table->index(['workspace_id', 'email_hash', 'status'], 'svpkr_ws_email_status_idx');
            $table->foreign(['workspace_id', 'service_package_id'], 'svpkr_ws_package_fk')
                ->references(['workspace_id', 'id'])->on('service_packages')->restrictOnDelete();
            $table->foreign(['workspace_id', 'service_package_version_id'], 'svpkr_ws_version_fk')
                ->references(['workspace_id', 'id'])->on('service_package_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_company_id'], 'svpkr_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
        });

        Schema::create('service_package_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('svpke_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedBigInteger('service_package_version_id');
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('client_project_id');
            $table->unsignedBigInteger('client_agreement_id');
            $table->unsignedBigInteger('service_package_request_id')->nullable();
            $table->string('status', 24);
            $table->unsignedBigInteger('offered_by_user_id')->nullable();
            $table->foreign('offered_by_user_id', 'svpke_offerer_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('offered_at');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'svpke_ws_id_unique');
            $table->unique(['workspace_id', 'client_agreement_id'], 'svpke_agreement_unique');
            $table->unique(['workspace_id', 'service_package_request_id'], 'svpke_request_unique');
            $table->index(['workspace_id', 'client_company_id', 'status'], 'svpke_ws_company_status_idx');
            $table->foreign(['workspace_id', 'service_package_id'], 'svpke_ws_package_fk')
                ->references(['workspace_id', 'id'])->on('service_packages')->restrictOnDelete();
            $table->foreign(['workspace_id', 'service_package_version_id'], 'svpke_ws_version_fk')
                ->references(['workspace_id', 'id'])->on('service_package_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_company_id'], 'svpke_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_project_id'], 'svpke_ws_project_fk')
                ->references(['workspace_id', 'id'])->on('client_projects')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_agreement_id'], 'svpke_ws_agreement_fk')
                ->references(['workspace_id', 'id'])->on('client_agreements')->restrictOnDelete();
            $table->foreign(['workspace_id', 'service_package_request_id'], 'svpke_ws_request_fk')
                ->references(['workspace_id', 'id'])->on('service_package_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_enrollments');
        Schema::dropIfExists('service_package_requests');
    }
};
