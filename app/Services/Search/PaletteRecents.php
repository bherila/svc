<?php

namespace App\Services\Search;

use App\Models\PaletteRecent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchResultKind;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;

/**
 * The last few records a person opened from the palette.
 *
 * ## Stored as ids, shown as the viewer sees them now
 *
 * A row is a kind and a public id. Reading the list resolves every row again
 * through {@see WorkspaceSearch::forRecents()}, which runs the same authorized
 * queries search runs, so a record the viewer can no longer open - a revoked
 * membership, a project they were removed from, a file un-shared - simply does
 * not come back, and a renamed record shows its new name. Recording does the
 * same check first: an id the viewer could not have opened is never stored, so
 * the table cannot be used to keep a pointer at someone else's record.
 *
 * Bounded at {@see KEEP} per person per workspace, pruned on every write.
 */
final class PaletteRecents
{
    public const KEEP = 8;

    public function __construct(private readonly WorkspaceSearch $search, private readonly WorkspaceClock $clock) {}

    /** Records that the viewer opened a record. False when they could not have. */
    public function record(User $user, string $workspacePublicId, SearchResultKind $kind, string $recordPublicId): bool
    {
        if (! in_array($kind, SearchResultKind::recentable(), true)) {
            return false;
        }

        $resolved = $this->search->forRecents($user, [$workspacePublicId => [$kind->value => [$recordPublicId]]]);

        if ($resolved === []) {
            return false;
        }

        $workspace = Workspace::query()->where('public_id', $workspacePublicId)->first();

        if (! $workspace instanceof Workspace) {
            return false;
        }

        DB::transaction(function () use ($user, $workspace, $kind, $recordPublicId): void {
            $now = $this->clock->now($workspace)->utc()->format('Y-m-d H:i:s.u');
            PaletteRecent::query()->upsert(
                [[
                    'workspace_id' => $workspace->id, 'user_id' => $user->id, 'kind' => $kind->value,
                    'record_public_id' => $recordPublicId, 'opened_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]],
                ['workspace_id', 'user_id', 'kind', 'record_public_id'],
                ['opened_at', 'updated_at'],
            );

            $stale = PaletteRecent::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)
                ->orderByDesc('opened_at')->orderByDesc('id')->skip(self::KEEP)->take(1000)->pluck('id')->all();

            if ($stale !== []) {
                PaletteRecent::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->whereIn('id', $stale)->delete();
            }
        });

        return true;
    }

    /**
     * The viewer's recent items, newest first, as they may open them today.
     *
     * @param  Workspace|null  $scope  Only this workspace's recents; null for all of the viewer's.
     * @return list<SearchResult>
     */
    public function for(User $user, ?Workspace $scope = null): array
    {
        $rows = PaletteRecent::query()->where('user_id', $user->id)
            ->when($scope instanceof Workspace, fn ($query) => $query->where('workspace_id', $scope?->id))
            ->with('workspace')
            ->orderByDesc('opened_at')->orderByDesc('id')->limit(self::KEEP * 3)->get();

        $wanted = [];
        foreach ($rows as $row) {
            $wanted[$row->workspace->public_id][$row->kind][] = $row->record_public_id;
        }

        if ($wanted === []) {
            return [];
        }

        $byKey = [];
        foreach ($this->search->forRecents($user, $wanted) as $result) {
            $byKey[$result->workspaceId.':'.$result->kind->value.':'.$result->id] = $result;
        }

        $recents = [];
        foreach ($rows as $row) {
            $result = $byKey[$row->workspace->public_id.':'.$row->kind.':'.$row->record_public_id] ?? null;
            if ($result instanceof SearchResult) {
                $recents[] = $result;
            }
            if (count($recents) === self::KEEP) {
                break;
            }
        }

        return $recents;
    }
}
