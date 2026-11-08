<?php

namespace App\Services\Search;

use App\Support\Search\SearchResultKind;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the palette is asking a kind's authorized query to match.
 *
 * Either a typed term, or a set of public ids the viewer opened before (their
 * recent items). Both run through the same authorized builder for each kind, so
 * a recent item is checked again against what the viewer may open today: a
 * revoked membership or a withdrawn share drops out of "recent" exactly as it
 * drops out of search, and no stored title is ever replayed.
 */
final readonly class SearchLookup
{
    /** Rows per kind for a typed term, so no one kind crowds the others out. */
    public const PER_KIND = 5;

    /** @param array<string, list<string>> $ids Public ids by kind value; used only when there is no term. */
    private function __construct(public ?string $term, public array $ids) {}

    public static function term(string $term): self
    {
        return new self($term, []);
    }

    /** @param array<string, list<string>> $idsByKind */
    public static function ids(array $idsByKind): self
    {
        return new self(null, $idsByKind);
    }

    /** Whether a kind takes part in this lookup. */
    public function wants(SearchResultKind $kind): bool
    {
        return $this->term !== null || ($this->ids[$kind->value] ?? []) !== [];
    }

    public function limit(SearchResultKind $kind): int
    {
        return $this->term !== null ? self::PER_KIND : count($this->ids[$kind->value] ?? []);
    }

    /**
     * Narrows a kind's authorized builder to this lookup.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function constrain(Builder $query, SearchColumn $column, SearchResultKind $kind): void
    {
        if ($this->term === null) {
            $query->whereIn($query->getModel()->getTable().'.public_id', $this->ids[$kind->value] ?? []);

            return;
        }

        $query->whereRaw($column->likeSql(), ['%'.WorkspaceSearch::escapeForLike($this->term).'%']);
    }
}
