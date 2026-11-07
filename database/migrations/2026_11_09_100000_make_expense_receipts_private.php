<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expense receipts open to a client only once a manager shares one. Until
     * the privacy latch was set, every upload defaulted to "visible to the
     * client", a flag nothing ever read for an expense, so each stored receipt
     * carries a value no one chose. Reading it now would publish them, so every
     * existing receipt starts private and a manager shares the ones to show.
     */
    public function up(): void
    {
        DB::table('client_attachments')
            ->where('record_type', 'expense')
            ->where('is_visible_to_client', true)
            ->update(['is_visible_to_client' => false]);
    }

    public function down(): void
    {
        // The earlier value was never a decision, and restoring it would share
        // receipts nobody chose to share.
    }
};
