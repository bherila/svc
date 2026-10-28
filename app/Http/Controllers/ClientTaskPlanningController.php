<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskPlanningInput;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\Planning\TaskPlanningDetailsGate;
use App\Services\Tasks\Planning\TaskPlanningNativePresenter;
use App\Services\Tasks\Planning\TaskPlanningTransport;
use App\Services\Tasks\TaskMutationOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/** Native policies and receipt identity are selected only by this trusted adapter. */
final class ClientTaskPlanningController extends Controller
{
    public function page(Request $request, Workspace $workspace, TaskPlanningNativePresenter $presenter): Response
    {
        TaskPlanningDetailsGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return Inertia::render('clients/task-labels', $presenter->catalog($actor, $workspace, $request->query()));
    }

    public function show(Request $request, Workspace $workspace, TaskPlanningTransport $transport): JsonResponse
    {
        TaskPlanningDetailsGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $operation = TaskPlanningInput::operation($request);

        $response = $transport->read($actor, $workspace, TaskMutationOrigin::Internal, $operation, TaskPlanningInput::target($request, $operation), $request->query());
        if (str_ends_with($operation, '.options') || $operation === 'dependencies.get') {
            $meta = $response['meta'] ?? null;
            if (! is_array($meta) || ! array_key_exists('next_cursor', $meta)) {
                throw new LogicException('Native planning pages must return explicit continuation metadata.');
            }
            $cursor = $meta['next_cursor'];
            if ($cursor !== null && ! is_string($cursor)) {
                throw new LogicException('Native planning continuation must be an opaque string.');
            }
            $response = ['data' => $response['data'],
                'next_href' => $cursor === null ? null : $request->fullUrlWithQuery(['cursor' => $cursor]),
                'previous_href' => null];
        }

        return response()->json($response);
    }

    public function options(Request $request, Workspace $workspace, TaskPlanningTransport $transport): JsonResponse
    {
        return $this->show($request, $workspace, $transport);
    }
}
