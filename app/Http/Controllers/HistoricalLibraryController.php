<?php

namespace App\Http\Controllers;

use App\Models\HistoricalBlob;
use App\Models\HistoricalEntry;
use App\Models\Workspace;
use App\Services\History\HistoricalLibraryReadService;
use App\Support\History\HistoryKind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HistoricalLibraryController extends Controller
{
    public function index(Request $request, Workspace $workspace, HistoricalLibraryReadService $library): Response
    {
        Gate::authorize('manage', $workspace);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'kind' => ['nullable', Rule::enum(HistoryKind::class)],
            'company' => ['nullable', 'uuid'], 'parent' => ['nullable', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $term = $data['q'] ?? '';
        $kind = isset($data['kind']) ? HistoryKind::from($data['kind']) : null;

        return Inertia::render('history/index', $library->listing($workspace, $term, $kind, $data['company'] ?? null, $data['parent'] ?? null, (int) ($data['page'] ?? 1)) + [
            'search_href' => route('history.index', $workspace, absolute: false),
            'filters' => ['q' => $term, 'kind' => $data['kind'] ?? '', 'company' => $data['company'] ?? '', 'parent' => $data['parent'] ?? ''],
            'kinds' => array_map(static fn (HistoryKind $type): array => ['value' => $type->value, 'label' => $type->label()], HistoryKind::cases()),
        ]);
    }

    public function show(Workspace $workspace, HistoricalEntry $historicalEntry, HistoricalLibraryReadService $library): Response
    {
        Gate::authorize('manage', $workspace);

        return Inertia::render('history/show', ['entry' => $library->detail($workspace, $historicalEntry)]);
    }

    public function download(Workspace $workspace, HistoricalBlob $historicalBlob): StreamedResponse
    {
        Gate::authorize('manage', $workspace);
        abort_unless($historicalBlob->workspaceId() === (int) $workspace->id, 404);
        HistoricalEntry::query()->where('workspace_id', $workspace->id)->whereKey($historicalBlob->historical_entry_id)->firstOrFail();
        $expectedKey = 'history/'.$workspace->public_id.'/'.$historicalBlob->sha256;
        abort_unless(hash_equals($expectedKey, $historicalBlob->object_key), 404);

        return Storage::disk('svc_files')->download($expectedKey, $historicalBlob->filename,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }
}
