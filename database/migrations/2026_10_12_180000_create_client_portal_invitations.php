<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal access promised to an address that has no account here yet.
 *
 * A manager invites an address; the identity provider admits that person to
 * this application, and the access is applied when they first sign in with a
 * matching address. Until then the promise lives here.
 *
 * - `email` is the invited address, lower-cased. The provider's link admits
 *   only that address, and sign-in applies only to it.
 * - `project_ids` are the projects a `projects` scope named, by id. They are
 *   checked again at sign-in, so a project that moved to another client since
 *   is dropped rather than granted.
 * - `pending_email` repeats `email` while the invitation is pending and is
 *   null otherwise, so the unique key allows one pending invitation per
 *   client and address and any number of finished ones.
 * - `link` is the provider's acceptance link, a single-use bearer credential.
 *   Encrypted, kept only while the invitation is pending, and shown only to
 *   managers on the client's settings page - never to agents.
 * - `inviter_user_id` is whose authority the invitation rests on. It is
 *   re-checked at sign-in; an inviter who has since lost it, or whose account
 *   is gone, leaves the invitation `not_applied`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_company_id');
            $table->string('email');
            $table->string('pending_email')->nullable();
            $table->string('access_scope', 20);
            $table->json('project_ids');
            $table->foreignId('inviter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('provider_invitation_id')->nullable();
            $table->text('link')->nullable();
            $table->boolean('email_sent')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20);
            $table->string('not_applied_reason', 40)->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'client_company_id'], 'cpi_ws_company_fk')
                ->references(['workspace_id', 'id'])->on('client_companies')->cascadeOnDelete();
            $table->unique(['workspace_id', 'client_company_id', 'pending_email'], 'portal_invitation_pending_unique');
            $table->index(['email', 'status'], 'portal_invitation_email_status_idx');
            $table->index(['workspace_id', 'client_company_id', 'status'], 'portal_invitation_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_invitations');
    }
};
