<?php

namespace App\Services\History;

use App\Models\ClientCompany;
use App\Models\HistoricalBlob;
use App\Models\HistoricalEntry;
use App\Models\Workspace;
use App\Support\History\HistoryKind;

/** Called only after manager authorization; never serializes source snapshots. */
final class HistoricalLibraryReadService
{
    public function __construct(private readonly HistorySearch $search) {}

    /** @return array<string, mixed> */
    public function listing(Workspace $workspace, string $term, ?HistoryKind $kind, ?string $company, ?string $parent, int $page): array
    {
        $query = HistoricalEntry::query()->where('workspace_id', $workspace->id);
        if ($kind !== null) {
            $query->where('kind', $kind->value);
        }
        if ($company !== null) {
            $parent = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $company)->firstOrFail();
            $query->where('client_company_id', $parent->id);
        }
        if ($parent !== null) {
            $parentEntry = HistoricalEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $parent)->firstOrFail();
            $query->where('parent_record_id', $parentEntry->historical_record_id);
        }
        $this->search->matching($query, $term);
        $rows = $query->orderByDesc('id')->simplePaginate(30, ['*'], 'page', $page);

        return ['entries' => array_map(fn (HistoricalEntry $entry): array => $this->summary($workspace, $entry), $rows->items()),
            'next_href' => $rows->hasMorePages() ? route('history.index', ['workspace' => $workspace, 'q' => $term, 'kind' => $kind?->value, 'company' => $company, 'parent' => $parent, 'page' => $page + 1], absolute: false) : null,
            'previous_href' => $page > 1 ? route('history.index', ['workspace' => $workspace, 'q' => $term, 'kind' => $kind?->value, 'company' => $company, 'parent' => $parent, 'page' => $page - 1], absolute: false) : null];
    }

    /** @return array<string, mixed> */
    public function detail(Workspace $workspace, HistoricalEntry $entry): array
    {
        $actual = HistoricalEntry::query()->where('workspace_id', $workspace->id)->whereKey($entry->id)->firstOrFail();
        $parent = $actual->parent_record_id === null ? null : HistoricalEntry::query()->where('workspace_id', $workspace->id)
            ->where('historical_record_id', $actual->parent_record_id)->first();
        $company = $actual->client_company_id === null ? null : ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($actual->client_company_id)->first();
        // Children are paged on the listing route as well, so long discussions
        // never disappear behind a silent truncation of their first messages.
        $children = HistoricalEntry::query()->where('workspace_id', $workspace->id)
            ->where('parent_record_id', $actual->historical_record_id)->orderBy('id')->limit(31)->get();
        $blob = HistoricalBlob::query()->where('workspace_id', $workspace->id)->where('historical_entry_id', $actual->id)->first();

        return $this->summary($workspace, $actual) + [
            'body' => $actual->body, 'fields' => $actual->details['fields'], 'tables' => $actual->details['tables'],
            'company' => $company === null ? null : ['name' => $company->name, 'href' => route('clients.show', [$workspace, $company], absolute: false)],
            'parent' => $parent === null ? null : $this->summary($workspace, $parent),
            'children' => $children->take(30)->map(fn (HistoricalEntry $child): array => $this->summary($workspace, $child))->all(),
            'children_href' => $children->count() > 30 ? route('history.index', ['workspace' => $workspace, 'parent' => $actual->public_id], absolute: false) : null,
            'download_href' => $blob === null ? null : route('history.download', [$workspace, $blob], absolute: false),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Workspace $workspace, HistoricalEntry $entry): array
    {
        return ['id' => $entry->public_id, 'title' => $entry->title, 'kind' => $entry->kind->label(),
            'author' => $entry->author, 'occurred_on' => $entry->occurred_on,
            'href' => route('history.show', [$workspace, $entry], absolute: false)];
    }
}
