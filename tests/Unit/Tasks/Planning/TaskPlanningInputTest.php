<?php

namespace Tests\Unit\Tasks\Planning;

use App\Http\Requests\TaskPlanningInput;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
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
