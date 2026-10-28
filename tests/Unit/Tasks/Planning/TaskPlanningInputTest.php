<?php

namespace Tests\Unit\Tasks\Planning;

use App\Http\Requests\TaskPlanningInput;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class TaskPlanningInputTest extends TestCase
{
    public function test_bounded_queries_are_operation_specific_and_do_not_accept_policy_or_path_selectors(): void
    {
        $this->assertSame(['q' => '', 'limit' => 25, 'cursor' => null, 'status' => 'archived'], TaskPlanningInput::query('labels.list', ['limit' => '25', 'q' => '', 'cursor' => null, 'status' => 'archived']));
        foreach ([['get', ['q' => 'x']], ['contacts.get', ['limit' => 1]], ['dependencies.get', ['q' => 'x']], ['labels.options', ['status' => 'all']],
            ['labels.list', ['origin' => 'Internal']], ['contacts.options', ['company_id' => 'x']], ['labels.list', ['limit' => 26]], ['labels.list', ['limit' => true]],
            ['dependencies.options', ['q' => str_repeat('x', 101)]], ['labels.options', ['cursor' => str_repeat('x', 2049)]]] as [$operation, $query]) {
            try {
                TaskPlanningInput::query($operation, $query);
                $this->fail('An unsupported planning query was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_http_empty_search_is_unfiltered_but_other_shapes_remain_closed(): void
    {
        foreach (['labels.list', 'labels.options', 'dependencies.options', 'contacts.options'] as $operation) {
            $this->assertSame(['q' => ''], TaskPlanningInput::query($operation, ['q' => null]));
            $this->assertSame(['q' => 'Synthetic'], TaskPlanningInput::query($operation, ['q' => 'Synthetic']));
        }
        foreach ([['labels.list', ['q' => ['Synthetic']]], ['get', ['q' => null]], ['dependencies.get', ['q' => null]]] as [$operation, $query]) {
            try {
                TaskPlanningInput::query($operation, $query);
                $this->fail('A non-search operation or array query was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_trusted_route_operation_has_its_exact_target_and_invalid_defaults_refuse_with_404(): void
    {
        foreach (['get' => 'task', 'labels.list' => null, 'labels.get' => 'label', 'labels.create' => null, 'labels.update' => 'label',
            'labels.archive' => 'label', 'labels.restore' => 'label', 'labels.options' => 'project', 'labels.replace' => 'task',
            'dependencies.get' => 'task', 'dependencies.options' => 'task', 'dependencies.replace' => 'task',
            'contacts.get' => 'task', 'contacts.options' => 'task', 'contacts.replace' => 'task'] as $operation => $target) {
            $request = $this->routeRequest($operation);
            $this->assertSame($operation, TaskPlanningInput::operation($request));
            $this->assertSame($target, TaskPlanningInput::targetName($operation));
            $this->assertSame([], TaskPlanningInput::query($operation === 'labels.create' || in_array($operation, ['labels.update', 'labels.archive', 'labels.restore', 'labels.replace', 'dependencies.replace', 'contacts.replace'], true) ? 'get' : $operation, []));
        }
        foreach ([null, 123, [], 'unknown-operation'] as $operation) {
            $this->assertHttp404(fn () => TaskPlanningInput::operation($this->routeRequest($operation)));
        }
        $this->assertHttp404(fn () => TaskPlanningInput::targetName('unknown-operation'));
        $this->assertHttp404(fn () => TaskPlanningInput::query('unknown-operation', []));
    }

    public function test_missing_non_string_empty_and_invalid_route_identities_are_never_body_fallbacks(): void
    {
        foreach ([null, 123, []] as $identity) {
            $this->assertHttp404(fn () => TaskPlanningInput::target($this->routeRequest('get', $identity), 'get'));
        }
        foreach (['', 'not-a-uuid'] as $identity) {
            try {
                TaskPlanningInput::target($this->routeRequest('get', $identity), 'get');
                $this->fail('An invalid target was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('target', $exception->errors());
            }
        }
    }

    public function test_dependency_page_bounds_and_query_refusals_keep_explicit_error_fields(): void
    {
        $this->assertSame(['limit' => 1, 'cursor' => null], TaskPlanningInput::query('dependencies.get', ['limit' => '1', 'cursor' => null]));
        $this->assertSame(['limit' => 25, 'cursor' => 'opaque'], TaskPlanningInput::query('dependencies.get', ['limit' => 25, 'cursor' => 'opaque']));
        foreach ([['get', ['foreign' => true], 'planning'], ['dependencies.get', ['limit' => false], 'limit'],
            ['dependencies.get', ['limit' => '1.5'], 'limit'], ['dependencies.get', ['limit' => 0], 'limit'], ['dependencies.get', ['limit' => 26], 'limit'],
            ['labels.list', ['status' => ['active']], 'status'], ['labels.list', ['status' => 'deleted'], 'status']] as [$operation, $query, $field]) {
            try {
                TaskPlanningInput::query($operation, $query);
                $this->fail('An invalid query was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    private function routeRequest(mixed $operation, mixed $target = '00000000-0000-4000-8000-000000000001'): Request
    {
        $request = Request::create('/synthetic', 'GET', ['task' => 'forged-body-target']);
        $route = new Route('GET', '/synthetic', []);
        $route->defaults('task_planning_operation', $operation)->defaults('task', $target)->bind($request);
        $request->setRouteResolver(fn (): Route => $route);

        return $request;
    }

    private function assertHttp404(callable $action): void
    {
        try {
            $action();
            $this->fail('An invalid route selector was accepted.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_targets_are_selected_by_trusted_route_defaults_and_never_request_body(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $request = Request::create('/synthetic/'.$id, 'PUT', ['task' => 'forged', 'task_planning_operation' => 'contacts.replace']);
        $route = new Route('PUT', '/synthetic/{task}', []);
        $route->defaults('task_planning_operation', 'labels.replace')->bind($request);
        $request->setRouteResolver(fn (): Route => $route);
        $this->assertSame('labels.replace', TaskPlanningInput::operation($request));
        $this->assertSame($id, TaskPlanningInput::target($request, 'labels.replace'));
        $this->assertNull(TaskPlanningInput::target($request, 'labels.create'));
        $this->assertSame('project', TaskPlanningInput::targetName('labels.options'));
        $this->assertSame('label', TaskPlanningInput::targetName('labels.archive'));
    }
}
