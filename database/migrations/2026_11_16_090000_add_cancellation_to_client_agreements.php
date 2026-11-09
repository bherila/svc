<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancellation (docs/service-packages.md, slice 5: CAN-1 to CAN-6, EDGE-9).
 *
 * - `client_agreements` gains its cancellation terms (CAN-1): a notice period
 *   in days, an optional minimum term in months and the portal mode (`none`,
 *   `request` or `self_serve`). Every agreement carries them; an agreement
 *   made from a package copies them from the version it carries, and one
 *   already made from a package before this migration is given them here
 *   from that version. Every other agreement starts at `none`: the portal
 *   offers no cancellation until an operator says otherwise.
 * - `agreement_cancellation_requests` is a company contact's request to end an
 *   agreement in `request` mode (CAN-3, CAN-4): the end date asked for, an
 *   optional reason, and the decision - approved (with the end date chosen,
 *   and whether notice was waived), declined (with a note), or withdrawn by
 *   the client.
 * - `agreement_scheduled_terminations` is an end that has been accepted but
 *   not reached (EDGE-9): the agreement stays active with `ends_on` set to
 *   the chosen date, and this row holds the `ends_on` it replaced, so undoing
 *   it restores that. On the date, `svc:agreements:terminate-scheduled`
 *   terminates the agreement and marks this row `terminated`.
 *
 * Both tables are tenant-owned: composite keys keep every reference inside
 * the row's workspace (docs/client-management/tenant-foreign-keys.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->unsignedSmallInteger('notice_days')->default(0)->after('first_cycle_proration');
            $table->unsignedSmallInteger('minimum_term_months')->nullable()->after('notice_days');
            $table->string('cancellation_mode', 16)->default('none')->after('minimum_term_months');
        });

        Schema::create('agreement_cancellation_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('agcr_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->unsignedBigInteger('client_agreement_id');
            $table->string('status', 16);
            $table->date('requested_end_on');
            $table->string('reason', 1000)->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->foreign('requested_by_user_id', 'agcr_requester_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->foreign('decided_by_user_id', 'agcr_decider_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->date('decided_end_on')->nullable();
            $table->boolean('notice_waived')->default(false);
            $table->string('decision_note', 1000)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'agcr_ws_id_unique');
            $table->index(['workspace_id', 'status', 'id'], 'agcr_ws_status_idx');
            $table->index(['workspace_id', 'client_agreement_id', 'status'], 'agcr_ws_agreement_status_idx');
            $table->foreign(['workspace_id', 'client_company_id'], 'agcr_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_agreement_id'], 'agcr_ws_agreement_fk')
                ->references(['workspace_id', 'id'])->on('client_agreements')->restrictOnDelete();
        });

        Schema::create('agreement_scheduled_terminations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('agst_public_id_unique');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_agreement_id');
            $table->unsignedBigInteger('agreement_cancellation_request_id')->nullable();
            $table->string('status', 16);
            $table->string('source', 16);
            $table->date('ends_on');
            $table->date('previous_ends_on')->nullable();
            $table->unsignedBigInteger('scheduled_by_user_id')->nullable();
            $table->foreign('scheduled_by_user_id', 'agst_scheduler_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->unsignedBigInteger('undone_by_user_id')->nullable();
            $table->foreign('undone_by_user_id', 'agst_undoer_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'agst_ws_id_unique');
            $table->index(['status', 'id'], 'agst_status_idx');
            $table->index(['workspace_id', 'client_agreement_id', 'status'], 'agst_ws_agreement_status_idx');
            $table->index(['workspace_id', 'agreement_cancellation_request_id'], 'agst_ws_request_idx');
            $table->foreign(['workspace_id', 'client_agreement_id'], 'agst_ws_agreement_fk')
                ->references(['workspace_id', 'id'])->on('client_agreements')->restrictOnDelete();
            $table->foreign(['workspace_id', 'agreement_cancellation_request_id'], 'agst_ws_request_fk')
                ->references(['workspace_id', 'id'])->on('agreement_cancellation_requests')->restrictOnDelete();
        });

        // A package agreement made before this migration carries its version's
        // terms (PKG-7): the version it was signed on, or until then offered on.
        DB::table('service_package_enrollments')
            ->select(['id', 'workspace_id', 'client_agreement_id', 'service_package_version_id', 'accepted_version_id'])
            ->orderBy('id')
            ->chunkById(200, function ($enrollments): void {
                foreach ($enrollments as $enrollment) {
                    $version = DB::table('service_package_versions')
                        ->where('workspace_id', $enrollment->workspace_id)
                        ->where('id', $enrollment->accepted_version_id ?? $enrollment->service_package_version_id)
                        ->first(['notice_days', 'minimum_term_months', 'cancellation_mode']);
                    if ($version === null) {
                        continue;
                    }
                    DB::table('client_agreements')
                        ->where('workspace_id', $enrollment->workspace_id)
                        ->where('id', $enrollment->client_agreement_id)
                        ->update([
                            'notice_days' => (int) $version->notice_days,
                            'minimum_term_months' => $version->minimum_term_months,
                            'cancellation_mode' => (string) $version->cancellation_mode,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_scheduled_terminations');
        Schema::dropIfExists('agreement_cancellation_requests');
        Schema::table('client_agreements', function (Blueprint $table): void {
            $table->dropColumn(['notice_days', 'minimum_term_months', 'cancellation_mode']);
        });
    }
};
