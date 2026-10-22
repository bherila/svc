<?php

namespace App\Http\Requests;

use App\Services\Tasks\Organization\TaskContainer;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Shared native/Agent input handling; route identities never come from a body. */
final class TaskOrganizationInput
{
    public static function routeIdentity(Request $request, string $name): ?string
    {
        $id = $request->route($name);
        abort_unless($id === null || is_string($id), 404);

        return $id;
    }

    public static function operation(Request $request): string
    {
        $operation = $request->route('task_organization_operation');
        abort_unless(is_string($operation), 404);

        return $operation;
    }

    /** @param array<string,mixed> $query */
    public static function container(array $query): ?TaskContainer
    {
        $unknown = array_diff(array_keys($query), ['container', 'parent_id', 'list_id']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['organization' => 'Unsupported organization field.']);
        }
        // HTTP query booleans arrive as strings; MCP/CLI supply actual booleans.
        if (($query['container'] ?? null) === 'true') {
            $query['container'] = true;
        } elseif (($query['container'] ?? null) === 'false') {
            $query['container'] = false;
        }
        $data = Validator::make($query, ['container' => ['sometimes', 'boolean'], 'parent_id' => ['nullable', 'uuid'], 'list_id' => ['nullable', 'uuid']])->validate();
        if (! ($data['container'] ?? false)) {
            if (($data['parent_id'] ?? null) !== null || ($data['list_id'] ?? null) !== null) {
                throw ValidationException::withMessages(['organization' => 'Choose a container before supplying its parent or list.']);
            }

            return null;
        }
        try {
            return new TaskContainer($data['parent_id'] ?? null, $data['list_id'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['organization' => $exception->getMessage()]);
        }
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    public static function body(array $body, ?string $list, ?string $task): array
    {
        $identity = array_filter(['list_id' => $list, 'task_id' => $task], static fn (?string $id): bool => $id !== null);
        if (array_intersect(array_keys($body), array_keys($identity)) !== []) {
            throw ValidationException::withMessages(['organization' => 'Supply the record identity in the route only.']);
        }

        return $identity + $body;
    }
}
