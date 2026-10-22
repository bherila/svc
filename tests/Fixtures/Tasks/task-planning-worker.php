<?php

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentClientContactMutationAction;
use App\Services\Clients\ContactMutationOrigin;
use App\Services\Tasks\Planning\TaskPlanningCommand;
use App\Services\Tasks\Planning\TaskPlanningMutationAction;
use App\Services\Tasks\WorkspaceTaskRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['database.connections.planning_race' => $input['connection'], 'database.default' => 'planning_race', 'task_planning_details.enabled' => true]);
DB::purge('planning_race');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
$workspace = Workspace::query()->findOrFail($input['workspace']);
$actor = User::query()->findOrFail($input['actor']);
try {
    if ($input['operation'] === 'portal') {
        DB::transaction(function () use ($workspace, $actor): void {
            app(WorkspaceTaskRepository::class)->lockWorkspace($workspace);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic newly granted company', 'slug' => 'synthetic-new-portal-company']);
            ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'client']);
        });
    } elseif ($input['operation'] === 'archive') {
        app(AgentClientContactMutationAction::class)->perform($actor, $workspace, $input['company'], 'archive', $input['target'], $input['body'],
            'synthetic-planning-archive-client', 'synthetic-planning-archive', ContactMutationOrigin::Internal);
    } else {
        echo "ready\n";
        flush();
        app(TaskPlanningMutationAction::class)->perform($actor, $workspace, $input['target'], TaskPlanningCommand::fromInput('dependencies.replace', $input['body']));
    }
    echo "committed\n";
} catch (HttpExceptionInterface $exception) {
    echo 'refused:'.$exception->getStatusCode()."\n";
} catch (ValidationException) {
    echo "refused:422\n";
}
