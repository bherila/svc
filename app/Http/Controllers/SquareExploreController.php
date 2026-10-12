<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Square\Mirror\SquareMirrorReadService;
use App\Services\Square\SquareConfiguration;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator's view of svc's Square mirror (#438): mirrored invoices with
 * filters, the recurring series, and when the mirror was last refreshed. It
 * reads only the mirror; the refresh control posts to square.sync. Workspace
 * managers only, and only where Square is connected.
 */
final class SquareExploreController extends Controller
{
    public const int SERIES_SHOWN = 100;

    private const array FILTERS = ['location_id', 'status', 'customer_id', 'series_key', 'due_from', 'due_to'];

    public function __invoke(Request $request, Workspace $workspace, SquareMirrorReadService $reads, AgentAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($access->isWorkspaceManager($user, $workspace), 403);
        abort_unless(SquareConfiguration::fromConfig()->servesWorkspace($workspace), 404);

        $requested = [];
        foreach (self::FILTERS as $filter) {
            $value = $request->query($filter);
            if (is_string($value) && $value !== '') {
                $requested[$filter] = $value;
            }
        }
        try {
            $filters = $reads->filters($requested);
        } catch (ValidationException) {
            // A hand-edited query string: show everything rather than an error page.
            $filters = [];
        }
        $page = $request->query('page');
        $seriesKeys = $reads->seriesKeys($workspace, self::SERIES_SHOWN + 1);

        return Inertia::render('square/explore', [
            'filters' => array_merge(array_fill_keys(self::FILTERS, null), $filters),
            'options' => [
                ...$reads->options($workspace),
                'statuses' => array_map(static fn (string $status): array => ['value' => $status], SquareMirrorReadService::STATUSES),
            ],
            'invoices' => $reads->explorePage($user, $workspace, $filters, is_string($page) && ctype_digit($page) ? (int) $page : 1),
            'series' => $reads->summaries($workspace, array_slice($seriesKeys, 0, self::SERIES_SHOWN)),
            'series_truncated' => count($seriesKeys) > self::SERIES_SHOWN,
            'sync' => [
                ...$reads->freshness($workspace),
                'sync_url' => route('agent-api.v1.square.sync', ['workspace' => $workspace->public_id], absolute: false),
            ],
            'explore_url' => route('square.explore', $workspace, absolute: false),
        ]);
    }
}
