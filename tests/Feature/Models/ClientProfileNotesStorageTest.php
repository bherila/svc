<?php

namespace Tests\Feature\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class ClientProfileNotesStorageTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_widening_preserves_existing_and_null_notes_and_can_restore_small_values(): void
    {
        $this->withNotesDatabase(function (): void {
            $migration = require database_path('migrations/2026_10_22_180020_widen_client_profile_notes.php');
            foreach (['client_companies', 'client_contacts'] as $table) {
                DB::table($table)->insert([['notes' => 'Synthetic existing notes'], ['notes' => null]]);
            }
            $migration->up();
            $migration->down();
            foreach (['client_companies', 'client_contacts'] as $table) {
                $this->assertSame(['Synthetic existing notes', null], DB::table($table)->orderBy('id')->pluck('notes')->all());
            }
        });
    }

    public function test_narrowing_refuses_large_contact_notes_without_changing_either_table(): void
    {
        $this->withNotesDatabase(function (): void {
            $migration = require database_path('migrations/2026_10_22_180020_widen_client_profile_notes.php');
            $migration->up();
            $notes = str_repeat('🚀', 20000);
            DB::table('client_companies')->insert(['notes' => 'Synthetic company notes']);
            DB::table('client_contacts')->insert(['notes' => $notes]);
            try {
                $migration->down();
                $this->fail('A schema rollback must not truncate valid client notes.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Cannot narrow client notes while values exceed the TEXT byte limit.', $exception->getMessage());
            }
            $this->assertSame($notes, DB::table('client_contacts')->value('notes'));
            // Both tables remain widened when the second table prevents rollback.
            DB::table('client_companies')->update(['notes' => $notes]);
            $this->assertSame($notes, DB::table('client_companies')->value('notes'));
        });
    }

    private function withNotesDatabase(callable $test): void
    {
        $this->bootProbeDatabase('client_notes_probe');
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('client_notes_probe');
        try {
            foreach (['client_companies', 'client_contacts'] as $table) {
                Schema::create($table, function (Blueprint $blueprint): void {
                    $blueprint->id();
                    $blueprint->text('notes')->nullable();
                });
            }
            $test();
        } finally {
            DB::setDefaultConnection($original);
        }
    }
}
