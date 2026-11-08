<?php

namespace Tests\Unit\Search;

use App\Services\Search\SearchColumn;
use App\Services\Search\SearchLookup;
use App\Support\Search\PaletteAction;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use PHPUnit\Framework\TestCase;

/**
 * The palette's small, database-free pieces: the kinds and how they are
 * ordered, the row a browser receives, a command, and what a lookup asks of a
 * kind's authorized query. The queries themselves are `Search\PaletteKindsTest`.
 */
final class PaletteValueObjectsTest extends TestCase
{
    public function test_every_kind_has_a_distinct_rank_and_a_heading(): void
    {
        $ranks = array_map(fn (SearchResultKind $kind): int => $kind->rank(), SearchResultKind::cases());

        $this->assertSame(range(0, count($ranks) - 1), $ranks, 'Ranks run in declaration order with no gap or repeat');
        $this->assertSame('Expenses', SearchResultKind::Expense->heading());
        $this->assertSame('Agreements', SearchResultKind::Agreement->heading());
        $this->assertSame('Proposals', SearchResultKind::Proposal->heading());
        $this->assertSame('Files', SearchResultKind::File->heading());
        $this->assertSame('Time', SearchResultKind::Time->heading());
        $this->assertSame('Contacts', SearchResultKind::Contact->heading());
        $this->assertSame('Portal users', SearchResultKind::PortalUser->heading());
        $this->assertSame(
            ['Clients', 'Projects', 'Invoices', 'Tasks', 'Notes', 'Discussions', 'Comments', 'History', 'Expenses', 'Agreements', 'Proposals', 'Files', 'Time', 'Contacts', 'Portal users'],
            array_map(fn (SearchResultKind $kind): string => $kind->heading(), SearchResultKind::cases()),
        );
    }

    public function test_the_original_kinds_list_by_title_and_the_new_ones_keep_their_query_order(): void
    {
        foreach ([SearchResultKind::Client, SearchResultKind::Project, SearchResultKind::Invoice, SearchResultKind::Task, SearchResultKind::Note, SearchResultKind::Discussion, SearchResultKind::Comment, SearchResultKind::History] as $kind) {
            $this->assertTrue($kind->listedByTitle(), $kind->value);
        }
        foreach ([SearchResultKind::Expense, SearchResultKind::Agreement, SearchResultKind::Proposal, SearchResultKind::File, SearchResultKind::Time, SearchResultKind::Contact, SearchResultKind::PortalUser] as $kind) {
            $this->assertFalse($kind->listedByTitle(), $kind->value);
        }
    }

    public function test_only_records_with_a_page_of_their_own_can_be_recents(): void
    {
        $this->assertSame(
            ['client', 'project', 'invoice', 'task', 'expense', 'agreement', 'proposal', 'file', 'contact', 'portal_user'],
            array_map(fn (SearchResultKind $kind): string => $kind->value, SearchResultKind::recentable()),
        );
    }

    public function test_the_agent_api_row_is_unchanged_and_the_palette_row_adds_to_it(): void
    {
        $result = new SearchResult(SearchResultKind::Invoice, 'inv-1', 'INV-1', 'Acme', '/x', 'Works', 'ws-1');

        $this->assertSame([
            'kind' => 'invoice', 'id' => 'inv-1', 'title' => 'INV-1', 'subtitle' => 'Acme', 'href' => '/x', 'workspace' => 'Works',
        ], $result->toArray());
        $this->assertSame([
            'kind' => 'invoice', 'id' => 'inv-1', 'title' => 'INV-1', 'subtitle' => 'Acme', 'href' => '/x', 'workspace' => 'Works',
            'workspace_id' => 'ws-1', 'heading' => 'Invoices', 'recent' => true,
        ], $result->toPaletteArray());

        $time = new SearchResult(SearchResultKind::Time, 't', 'Work', null, '/t', 'Works');
        $this->assertFalse($time->toPaletteArray()['recent']);
        $this->assertSame('', $time->toPaletteArray()['workspace_id']);
    }

    public function test_a_command_serialises_with_its_keywords_and_hint(): void
    {
        $this->assertSame(
            ['id' => 'log_time', 'label' => 'Log time', 'href' => '/t', 'keywords' => ['hours'], 'hint' => 'Choose a client'],
            (new PaletteAction('log_time', 'Log time', '/t', ['hours'], 'Choose a client'))->toArray(),
        );
        $this->assertSame([], (new PaletteAction('a', 'A', '/a'))->toArray()['keywords']);
        $this->assertNull((new PaletteAction('a', 'A', '/a'))->toArray()['hint']);
    }

    public function test_a_term_lookup_takes_every_kind_with_the_bounded_limit(): void
    {
        $lookup = SearchLookup::term('acme');

        $this->assertSame('acme', $lookup->term);
        foreach (SearchResultKind::cases() as $kind) {
            $this->assertTrue($lookup->wants($kind));
            $this->assertSame(5, $lookup->limit($kind));
        }
    }

    public function test_an_id_lookup_takes_only_the_kinds_it_names_and_exactly_those_rows(): void
    {
        $lookup = SearchLookup::ids(['client' => ['a', 'b'], 'file' => ['c']]);

        $this->assertNull($lookup->term);
        $this->assertTrue($lookup->wants(SearchResultKind::Client));
        $this->assertTrue($lookup->wants(SearchResultKind::File));
        $this->assertFalse($lookup->wants(SearchResultKind::Project));
        $this->assertSame(2, $lookup->limit(SearchResultKind::Client));
        $this->assertSame(1, $lookup->limit(SearchResultKind::File));
        $this->assertSame(0, $lookup->limit(SearchResultKind::Project));
    }

    public function test_the_new_columns_name_their_escape_character_like_the_old_ones(): void
    {
        foreach ([SearchColumn::Description, SearchColumn::ClientVisibleDescription, SearchColumn::FirstName, SearchColumn::LastName, SearchColumn::Email] as $column) {
            $this->assertStringEndsWith(" LIKE ? ESCAPE '!'", $column->likeSql());
            $this->assertStringNotContainsString('\\', $column->likeSql());
        }
        $this->assertSame("description LIKE ? ESCAPE '!'", SearchColumn::Description->likeSql());
        $this->assertSame("client_visible_description LIKE ? ESCAPE '!'", SearchColumn::ClientVisibleDescription->likeSql());
        $this->assertSame("first_name LIKE ? ESCAPE '!'", SearchColumn::FirstName->likeSql());
        $this->assertSame("last_name LIKE ? ESCAPE '!'", SearchColumn::LastName->likeSql());
        $this->assertSame("email LIKE ? ESCAPE '!'", SearchColumn::Email->likeSql());
    }
}
