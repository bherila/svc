<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lock key for one address's sign-up requests in a workspace (#667).
 *
 * A public submission counts the address's open requests and then inserts
 * one; two at once from one address could each count under the cap and both
 * insert. Each submission first upserts this row and locks it, so they are
 * serialized per workspace and address, including an address with no request
 * yet, without locking the workspace row every workspace-scoped insert's
 * foreign key check reads.
 *
 * Only the keyed hash the requests already carry (`email_hash`), never the
 * address. The row holds no data of its own: it may be deleted at any time,
 * and erasing a request deletes it.
 *
 * Tenant-owned (docs/client-management/tenant-foreign-keys.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_package_request_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->char('email_hash', 64);
            $table->timestamps();
            $table->unique(['workspace_id', 'email_hash'], 'svpkra_ws_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_request_addresses');
    }
};
