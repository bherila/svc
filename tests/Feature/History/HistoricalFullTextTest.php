<?php

namespace Tests\Feature\History;

use App\Models\HistoricalEntry;
use App\Models\Workspace;
use App\Services\History\HistoricalLibraryRepository;
use App\Services\History\HistoricalRecordRepository;
use App\Services\History\HistorySearch;
use App\Support\History\HistoryDocument;
use App\Support\History\HistoryKind;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalFullTextTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires the MariaDB CI lane, not the SQLite test adapter.');
        }
        // InnoDB FULLTEXT only sees committed rows, so transaction-wrapped
        // RefreshDatabase fixtures cannot establish that the live index works.
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_mariadb_fulltext_matches_non_adjacent_words_after_commit(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic indexed history', 'slug' => 'synthetic-indexed']);
        $records = app(HistoricalRecordRepository::class);
        $source = $records->source($workspace, 'synthetic', hash('sha256', 'source'), 'Synthetic');
        $record = $records->remember($workspace, $source, 'notes', '1', ['body' => 'Constellation distant quasar']);
        $entry = app(HistoricalLibraryRepository::class)->publish($workspace, $record, new HistoryDocument(HistoryKind::Note, 'Original note', 'Constellation distant quasar'));
        $query = HistoricalEntry::query()->where('workspace_id', $workspace->id);
        app(HistorySearch::class)->matching($query, 'constellation quasar');
        $this->assertSame([$entry->id], $query->pluck('id')->all(), 'The phrase is not a literal substring: this must use the committed FULLTEXT index.');
        $indexes = DB::select("SHOW INDEX FROM historical_entries WHERE Key_name='historical_entries_text_index'");
        $this->assertCount(2, $indexes);
        $this->assertSame('FULLTEXT', $indexes[0]->Index_type);
    }
}
