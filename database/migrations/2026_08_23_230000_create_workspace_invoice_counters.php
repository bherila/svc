<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invoice_counters', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number');
            $table->timestamps();
        });

        $now = now();
        foreach (DB::table('workspaces')->orderBy('id')->pluck('id') as $workspaceId) {
            $highest = 0;
            foreach (DB::table('client_invoices')->where('workspace_id', $workspaceId)->pluck('invoice_number') as $invoiceNumber) {
                if (is_string($invoiceNumber) && preg_match('/^SVC-(\d+)$/', $invoiceNumber, $matches) === 1) {
                    $highest = max($highest, (int) $matches[1]);
                }
            }
            DB::table('workspace_invoice_counters')->insert([
                'workspace_id' => $workspaceId,
                'next_number' => $highest + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invoice_counters');
    }
};
