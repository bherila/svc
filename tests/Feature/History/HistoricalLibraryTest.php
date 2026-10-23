<?php

namespace Tests\Feature\History;

use App\Models\ClientCompany;
use App\Models\HistoricalEntry;
use App\Models\HistoricalRecord;
use App\Models\User;
use App\Models\Workspace;
use App\Services\History\HistoricalBlobRepository;
use App\Services\History\HistoricalLibraryRepository;
use App\Services\History\HistoricalRecordRepository;
use App\Support\History\HistoryDocument;
use App\Support\History\HistoryKind;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HistoricalLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_comments_notes_and_discussions_retain_content_authors_dates_and_parent_links(): void
    {
        [$user, $workspace] = $this->manager();
        $project = $this->entry($workspace, 'projects', '1', HistoryKind::Project, 'Synthetic project', 'Original project');
        $discussion = $this->entry($workspace, 'discussions', '1', HistoryKind::Discussion, 'Synthetic discussion', '<script>never execute</script>', $this->record($workspace, $project));
        $comment = $this->entry($workspace, 'comments', '1', HistoryKind::Comment, 'Synthetic comment', 'Original comment', $this->record($workspace, $discussion));
        $note = $this->entry($workspace, 'notes', '1', HistoryKind::Note, 'Synthetic note', 'Original note', $this->record($workspace, $project));

        $this->actingAs($user)->get(route('history.show', [$workspace, $discussion]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('history/show')->where('entry.body', '<script>never execute</script>')
            ->where('entry.author', 'Casey Example')->where('entry.occurred_on', '2020-03-04 12:34:56')
            ->where('entry.parent.href', route('history.show', [$workspace, $project], absolute: false))
            ->where('entry.children.0.href', route('history.show', [$workspace, $comment], absolute: false))
            ->missing('entry.snapshot')->missing('entry.object_key'));
        $this->get(route('history.show', [$workspace, $project]))->assertInertia(fn (Assert $page) => $page->has('entry.children', 2));
        $this->get(route('history.show', [$workspace, $note]))->assertOk();
    }

    public function test_unified_search_finds_historical_body_author_fields_and_tables_without_exposing_foreign_or_portal_data(): void
    {
        [$user, $workspace] = $this->manager();
        $entry = $this->entry($workspace, 'notes', '1', HistoryKind::Note, 'Synthetic note', 'DistinctiveBody');
        $other = Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->entry($other, 'notes', '1', HistoryKind::Note, 'Foreign distinctiveBody', 'Foreign private content');
        foreach (['DistinctiveBody', 'Casey', 'PO-SYN-1', 'Synthetic line detail'] as $term) {
            $this->actingAs($user)->getJson('/search?'.http_build_query(['q' => $term]))->assertOk()
                ->assertJsonCount(1, 'results')->assertJsonPath('results.0.kind', 'history')
                ->assertJsonPath('results.0.href', route('history.show', [$workspace, $entry], absolute: false));
        }
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAs($member)->getJson('/search?q=DistinctiveBody')->assertOk()->assertExactJson(['results' => []]);
        $this->get(route('history.show', [$workspace, $entry]))->assertForbidden();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson('/search?q=DistinctiveBody')->assertOk()->assertExactJson(['results' => []]);
        $this->get(route('history.index', $workspace))->assertForbidden();
    }

    public function test_search_keeps_short_words_punctuation_and_literal_wildcards(): void
    {
        [$user, $workspace] = $this->manager();
        $entry = $this->entry($workspace, 'notes', '1', HistoryKind::Note, 'Original SYN/50%', 'A note with xy and a_b! tokens.');
        $this->entry($workspace, 'notes', '2', HistoryKind::Note, 'Original SYN/500', 'Unrelated');
        foreach (['50%', 'a_b!', 'xy', '%'] as $term) {
            $this->actingAs($user)->getJson('/search?'.http_build_query(['q' => $term]))->assertOk()
                ->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $entry->public_id);
        }
    }

    public function test_replay_is_idempotent_and_changed_documents_are_refused_without_billing_or_authentication_effects(): void
    {
        Mail::fake();
        Queue::fake();
        [, $workspace] = $this->manager();
        $entry = $this->entry($workspace, 'invoices', '1', HistoryKind::Invoice, 'SYN-OLD-1', 'Canceled historical balance 120.00');
        $again = $this->entry($workspace, 'invoices', '1', HistoryKind::Invoice, 'SYN-OLD-1', 'Canceled historical balance 120.00');
        $this->assertSame($entry->id, $again->id);
        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertDatabaseCount('client_tasks', 0);
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertDatabaseCount('client_company_memberships', 0);
        $this->assertDatabaseCount('client_portal_invitations', 0);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        $this->expectException(DomainException::class);
        app(HistoricalLibraryRepository::class)->publish($workspace, $this->record($workspace, $entry), new HistoryDocument(HistoryKind::Invoice, 'Replacement', 'Changed'));
    }

    public function test_source_parent_and_company_scope_cannot_be_forged(): void
    {
        [, $workspace] = $this->manager();
        $other = Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreign = $this->entry($other, 'projects', '1', HistoryKind::Project, 'Foreign', '');
        $mine = $this->entry($workspace, 'notes', '1', HistoryKind::Note, 'Mine', '');
        $company = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign']);
        foreach ([[$this->record($other, $foreign), null, null], [$this->record($workspace, $mine), $company, null], [$this->record($workspace, $mine), null, $this->record($other, $foreign)]] as [$record, $target, $parent]) {
            try {
                app(HistoricalLibraryRepository::class)->publish($workspace, $record, new HistoryDocument(HistoryKind::Note, 'Nope', ''), $target, $parent);
                $this->fail('Cross-tenant lineage must be refused.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('historical_entries', 2);
            }
        }
    }

    public function test_files_are_digest_checked_private_downloads_and_replays_repair_missing_bytes(): void
    {
        Storage::fake('svc_files');
        [$user, $workspace] = $this->manager();
        $entry = $this->entry($workspace, 'attachments', '1', HistoryKind::File, 'Synthetic attachment', '');
        $path = tempnam(sys_get_temp_dir(), 'svc-synthetic-history-');
        $this->assertIsString($path);
        file_put_contents($path, 'Synthetic historical content');
        $sha = hash_file('sha256', $path);
        $repo = app(HistoricalBlobRepository::class);
        try {
            $blob = $repo->preserve($workspace, $entry, $path, 'synthetic.txt', $sha, filesize($path));
            $this->actingAs($user)->get(route('history.download', [$workspace, $blob]))->assertOk()
                ->assertHeader('Content-Type', 'application/octet-stream')->assertDownload('synthetic.txt')
                ->assertStreamedContent('Synthetic historical content');
            Storage::disk('svc_files')->delete($blob->object_key);
            $again = $repo->preserve($workspace, $entry, $path, 'synthetic.txt', $sha, filesize($path));
            $this->assertSame($blob->id, $again->id);
            $this->assertSame('Synthetic historical content', Storage::disk('svc_files')->get($blob->object_key));
            $outsider = User::factory()->create();
            $this->actingAs($outsider)->get(route('history.download', [$workspace, $blob]))->assertForbidden();
            file_put_contents($path, 'Corrupted');
            $this->expectException(DomainException::class);
            $repo->preserve($workspace, $entry, $path, 'synthetic.txt', $sha, $blob->bytes);
        } finally {
            unlink($path);
        }
    }

    public function test_related_records_are_fully_reachable_through_pagination(): void
    {
        [$user, $workspace] = $this->manager();
        $discussion = $this->entry($workspace, 'discussions', '1', HistoryKind::Discussion, 'Thread', '');
        for ($n = 1; $n <= 33; $n++) {
            $this->entry($workspace, 'comments', (string) $n, HistoryKind::Comment, 'Comment '.$n, '', $this->record($workspace, $discussion));
        }
        $this->actingAs($user)->get(route('history.show', [$workspace, $discussion]))->assertInertia(fn (Assert $page) => $page->has('entry.children', 30)->where('entry.children_href', route('history.index', ['workspace' => $workspace, 'parent' => $discussion->public_id], absolute: false)));
        $this->get(route('history.index', ['workspace' => $workspace, 'parent' => $discussion->public_id, 'page' => 2]))->assertInertia(fn (Assert $page) => $page->has('entries', 3)->where('next_href', null));
    }

    public function test_company_filter_preserves_parent_filter_pagination_and_workspace_isolation(): void
    {
        [$user, $workspace] = $this->manager();
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other client', 'slug' => 'other-client']);
        $discussion = $this->entry($workspace, 'discussions', '1', HistoryKind::Discussion, 'Thread', '');
        for ($n = 1; $n <= 31; $n++) {
            $this->entry($workspace, 'comments', (string) $n, HistoryKind::Comment, 'Comment '.$n, '', $this->record($workspace, $discussion), $company);
        }
        $this->entry($workspace, 'notes', '1', HistoryKind::Note, 'Other parent', '', null, $company);
        $this->entry($workspace, 'comments', '32', HistoryKind::Comment, 'Other company', '', $this->record($workspace, $discussion), $otherCompany);
        $parameters = ['workspace' => $workspace, 'q' => '', 'company' => $company->public_id, 'parent' => $discussion->public_id];

        $this->actingAs($user)->get(route('history.index', ['workspace' => $workspace, 'company' => $company->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries', 30));
        $this->get(route('history.index', $parameters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries', 30)->where('next_href', route('history.index', $parameters + ['page' => 2], absolute: false)));
        $this->get(route('history.index', $parameters + ['page' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)->where('next_href', null)->where('previous_href', route('history.index', $parameters + ['page' => 1], absolute: false)));

        $foreign = Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Foreign client', 'slug' => 'foreign-client']);
        $foreignParent = $this->entry($foreign, 'discussions', '1', HistoryKind::Discussion, 'Foreign thread', '');
        $this->get(route('history.index', ['workspace' => $workspace, 'company' => $foreignCompany->public_id]))->assertNotFound();
        $this->get(route('history.index', ['workspace' => $workspace, 'company' => $company->public_id, 'parent' => $foreignParent->public_id]))->assertNotFound();
    }

    /** @return array{User, Workspace} */
    private function manager(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic history', 'slug' => 'synthetic-history']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);

        return [$user, $workspace];
    }

    private function entry(Workspace $workspace, string $table, string $key, HistoryKind $kind, string $title, string $body, ?HistoricalRecord $parent = null, ?ClientCompany $company = null): HistoricalEntry
    {
        $repo = app(HistoricalRecordRepository::class);
        $source = $repo->source($workspace, 'synthetic', hash('sha256', 'source'), 'Synthetic history');
        $record = $repo->remember($workspace, $source, $table, $key, ['original' => $title, 'body' => $body]);

        return app(HistoricalLibraryRepository::class)->publish($workspace, $record,
            new HistoryDocument($kind, $title, $body, 'Casey Example', '2020-03-04 12:34:56', [['label' => 'Purchase order', 'value' => 'PO-SYN-1']],
                [['label' => 'Items', 'columns' => ['Description', 'Quantity'], 'rows' => [['Synthetic line detail', '0.35']]]]), $company, $parent);
    }

    private function record(Workspace $workspace, HistoricalEntry $entry): HistoricalRecord
    {
        return HistoricalRecord::query()->where('workspace_id', $workspace->id)->whereKey($entry->historical_record_id)->firstOrFail();
    }
}
