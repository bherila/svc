<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Closed adapter input; the route chooses its operation, target and audience. */
final class TaskPlanningInput
{
    public const array READS = ['get', 'labels.list', 'labels.get', 'labels.options', 'dependencies.get', 'dependencies.options', 'contacts.get', 'contacts.options'];

    public const array WRITES = ['labels.create', 'labels.update', 'labels.archive', 'labels.restore', 'labels.replace', 'dependencies.replace', 'contacts.replace'];

    public static function operation(Request $request): string
    {
        $operation = $request->route('task_planning_operation');
        abort_unless(is_string($operation) && in_array($operation, [...self::READS, ...self::WRITES], true), 404);

        return $operation;
    }

    public static function targetName(string $operation): ?string
    {
        return match ($operation) {
            'labels.list', 'labels.create' => null,
            'labels.get', 'labels.update', 'labels.archive', 'labels.restore' => 'label',
            'labels.options' => 'project',
            'get', 'labels.replace', 'dependencies.get', 'dependencies.options', 'dependencies.replace', 'contacts.get', 'contacts.options', 'contacts.replace' => 'task',
            default => abort(404),
        };
    }

    public static function target(Request $request, string $operation): ?string
    {
        $name = self::targetName($operation);
        if ($name === null) {
            return null;
        }
        $target = $request->route($name);
        abort_unless(is_string($target), 404);
        Validator::make(['target' => $target], ['target' => ['required', 'uuid']])->validate();

        return $target;
    }

    /** @param array<string,mixed> $query
     * @return array{q?:string,status?:string,limit?:int,cursor?:?string} */
    public static function query(string $operation, array $query): array
    {
        $rules = match ($operation) {
            'get', 'labels.get', 'contacts.get' => [],
            'dependencies.get' => ['limit' => ['integer', 'min:1', 'max:25'], 'cursor' => ['nullable', 'string', 'max:2048']],
            'labels.list', 'labels.options', 'dependencies.options', 'contacts.options' => ['q' => ['string', 'max:100'],
                'limit' => ['integer', 'min:1', 'max:25'], 'cursor' => ['nullable', 'string', 'max:2048']],
            default => abort(404),
        };
        if ($operation === 'labels.list') {
            $rules['status'] = ['string', 'in:active,archived,all'];
        }
        if (array_diff(array_keys($query), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['planning' => 'Unsupported task planning query.']);
        }
        // Laravel's HTTP middleware converts an empty query string to null.
        // An absent/empty search is the same unfiltered page, including when a
        // finished catalog continuation retains q=. Arrays and unknown fields
        // still pass through the closed validation below.
        if (array_key_exists('q', $query) && $query['q'] === null) {
            $query['q'] = '';
        }
        if (array_key_exists('limit', $query) && ! is_int($query['limit']) && ! is_string($query['limit'])) {
            throw ValidationException::withMessages(['limit' => 'Choose a whole-number page size.']);
        }
        $data = Validator::make($query, $rules)->validate();
        if (isset($data['limit'])) {
            // HTTP query numbers arrive as strings; typed MCP/CLI values are integers.
            $limit = filter_var($data['limit'], FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw ValidationException::withMessages(['limit' => 'Choose a whole-number page size.']);
            }
            $data['limit'] = $limit;
        }

        return $data;
    }
}
