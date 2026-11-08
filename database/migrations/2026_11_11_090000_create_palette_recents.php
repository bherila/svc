<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The command palette's "recent items": the last few records a person opened
 * from it, per workspace.
 *
 * A row holds a kind and a public id and nothing else - no title, no link. What
 * the palette shows is resolved again, through the same authorized queries
 * search uses, every time the list is read. A stored title would be a copy of a
 * record's name kept after the person lost the right to read it.
 *
 * One row per (person, workspace, kind, record); opening it again moves it to
 * the front rather than adding another. The application keeps at most eight per
 * person per workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('palette_recents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->char('record_public_id', 36);
            // A dateTime, not a timestamp: see create_invoice_reminder_logs.
            $table->dateTime('opened_at', 6);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id', 'kind', 'record_public_id'], 'palette_recents_record_unique');
            $table->index(['user_id', 'workspace_id', 'opened_at'], 'palette_recents_user_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palette_recents');
    }
};
