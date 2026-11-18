<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            // Nonsecret row identities, never a key or a hash of stored credentials.
            // Null means no stored Stripe connection has ever replaced the legacy path.
            $table->string('stripe_credential_version', 96)->nullable();
        });
        Schema::table('workspace_payment_connections', function (Blueprint $table): void {
            $table->index(['reuses_connection_id', 'id'], 'payment_connection_reuse_source_idx');
        });
        DB::table('workspaces')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('workspace_payment_connections')
                ->whereColumn('workspace_payment_connections.workspace_id', 'workspaces.id')
                ->where('processor', 'stripe');
        })->update(['stripe_credential_version' => 'disconnected']);

        DB::table('workspace_payment_connections as local')
            ->leftJoin('workspace_payment_connections as source', 'source.id', '=', 'local.reuses_connection_id')
            ->where('local.processor', 'stripe')->whereNull('local.revoked_at')
            ->select(['local.id', 'local.workspace_id', 'local.reuses_connection_id', 'local.external_account_id', 'local.connected_at',
                'source.id as source_id', 'source.processor as source_processor', 'source.revoked_at as source_revoked_at',
                'source.reuses_connection_id as source_reuses_id', 'source.external_account_id as source_account'])
            ->orderBy('local.id')->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $sourceId = $row->id;
                    if ($row->reuses_connection_id !== null) {
                        if ($row->source_id === null || $row->source_processor !== 'stripe' || $row->source_revoked_at !== null || $row->source_reuses_id !== null || $row->source_account !== $row->external_account_id) {
                            continue;
                        }
                        $sourceId = $row->source_id;
                    }
                    // This immutable format also identifies the rows captured alongside
                    // the actual key by PaymentConnectionResolver. No timestamps suffice.
                    DB::table('workspaces')->where('id', $row->workspace_id)->update([
                        'stripe_credential_version' => 'connection:'.$row->id.':source:'.$sourceId,
                    ]);
                    DB::table('workspaces')->where('id', $row->workspace_id)
                        ->where('stripe_refused_at', '<', $row->connected_at)->update(['stripe_refused_at' => null]);
                }
            }, 'local.id', 'id');
    }

    public function down(): void
    {
        Schema::table('workspace_payment_connections', function (Blueprint $table): void {
            $table->dropIndex('payment_connection_reuse_source_idx');
        });
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('stripe_credential_version');
        });
    }
};
