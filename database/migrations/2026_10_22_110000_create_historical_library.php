<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historical_records', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'historical_records_ws_id_unique');
        });
        Schema::create('historical_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('historical_record_id');
            $table->unsignedBigInteger('parent_record_id')->nullable();
            $table->unsignedBigInteger('client_company_id')->nullable();
            $table->string('kind', 32);
            $table->string('title', 512);
            $table->longText('body');
            $table->longText('search_text');
            $table->string('author', 255)->nullable();
            $table->string('occurred_on', 40)->nullable();
            $table->json('details');
            $table->char('document_fingerprint', 64);
            $table->timestamps();
            $table->unique(['workspace_id', 'id'], 'historical_entries_ws_id_unique');
            $table->unique(['workspace_id', 'historical_record_id'], 'historical_entries_record_unique');
            $table->index(['workspace_id', 'kind', 'id'], 'historical_entries_kind_index');
            $table->index(['workspace_id', 'parent_record_id'], 'historical_entries_parent_index');
            $table->foreign(['workspace_id', 'historical_record_id'], 'history_entry_record_fk')->references(['workspace_id', 'id'])->on('historical_records')->restrictOnDelete();
            $table->foreign(['workspace_id', 'parent_record_id'], 'history_entry_parent_fk')->references(['workspace_id', 'id'])->on('historical_records')->restrictOnDelete();
            $table->foreign(['workspace_id', 'client_company_id'], 'history_entry_company_fk')->references(['workspace_id', 'id'])->on('client_companies')->restrictOnDelete();
        });
        // SQLite remains the disposable test adapter; MariaDB owns the live index.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('historical_entries', fn (Blueprint $table) => $table->fullText(['title', 'search_text'], 'historical_entries_text_index'));
        }
        Schema::create('historical_blobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('historical_entry_id');
            $table->string('filename', 512);
            $table->string('object_key', 191);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['workspace_id', 'historical_entry_id'], 'history_blob_entry_unique');
            $table->foreign(['workspace_id', 'historical_entry_id'], 'history_blob_entry_fk')->references(['workspace_id', 'id'])->on('historical_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_blobs');
        Schema::dropIfExists('historical_entries');
        Schema::table('historical_records', fn (Blueprint $table) => $table->dropUnique('historical_records_ws_id_unique'));
    }
};
